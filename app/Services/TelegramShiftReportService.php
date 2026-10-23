<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Posts each closed shift's items sold and raw materials used to the owner's Telegram group.
 *
 * - Driven by the every-minute `telegram:shift-reports` schedule, never by the close itself: a
 *   shift closes the same whether the internet is up or not, and an unsent report simply goes
 *   out on a later run.
 * - A shift's summary is sent once (shifts.sales_report_sent_at), and each paid ticket is counted
 *   once (tickets.sales_report_sent_at). A ticket paid after its shift closed - an offline sale
 *   synced late - goes out as a short "late sales" follow-up.
 * - Nothing is marked sent unless Telegram accepted the message, so a failure is retried.
 * - Shifts that closed more than LOOKBACK_DAYS ago are never sent automatically.
 * - Does nothing until TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID are set.
 */
class TelegramShiftReportService
{
    public const LOOKBACK_DAYS = 2;

    /** Telegram's limit is 4096 characters per message; kept under it for safety. */
    private const MESSAGE_LIMIT = 4000;

    public function __construct(private SalesReportService $salesReportService) {}

    public function IsConfigured(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }

    /**
     * Sends everything not yet sent for recently closed shifts. One shift failing doesn't stop
     * the others.
     *
     * @return array<int, array{shift_id: int, sent: bool, error: string|null}>
     */
    public function SendPending(): array
    {
        if (! $this->IsConfigured()) {
            return [];
        }

        $shifts = Shift::query()
            ->where('status', 'closed')
            ->where('closed_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where(fn ($query) => $query
                ->whereNull('sales_report_sent_at')
                ->orWhereHas('tickets', fn ($tickets) => $tickets->where('status', 'paid')->whereNull('sales_report_sent_at')))
            ->orderBy('closed_at')
            ->get();

        return $shifts->map(function (Shift $shift): array {
            try {
                return ['shift_id' => $shift->id, 'sent' => $this->SendForShift($shift), 'error' => null];
            } catch (Throwable $e) {
                report($e);

                return ['shift_id' => $shift->id, 'sent' => false, 'error' => $e->getMessage()];
            }
        })->all();
    }

    /**
     * Sends what this closed shift hasn't sent yet: its summary the first time, then any late
     * sales. Returns whether a message went out.
     */
    public function SendForShift(Shift $shift): bool
    {
        // Two overlapping runs must not send the same shift twice.
        return (bool) Cache::lock("shift-sales-report:{$shift->id}", 120)->get(function () use ($shift): bool {
            $shift = $shift->fresh();

            if ($shift === null || $shift->isOpen()) {
                return false;
            }

            $ticketIds = $shift->tickets()
                ->where('status', 'paid')
                ->whereNull('sales_report_sent_at')
                ->orderBy('id')
                ->pluck('id')
                ->all();
            $isFollowUp = $shift->sales_report_sent_at !== null;

            if ($isFollowUp && $ticketIds === []) {
                return false;
            }

            $this->send($isFollowUp ? $this->lateSalesMessage($shift, $ticketIds) : $this->closingMessage($shift, $ticketIds));

            DB::transaction(function () use ($shift, $ticketIds, $isFollowUp): void {
                Ticket::query()->whereKey($ticketIds)->update(['sales_report_sent_at' => now()]);

                if (! $isFollowUp) {
                    $shift->update(['sales_report_sent_at' => now()]);
                }
            });

            return true;
        });
    }

    /**
     * The whole shift again (everything paid on it, late sales included), on request. Changes
     * nothing about what counts as sent.
     */
    public function Resend(Shift $shift): void
    {
        $this->send($this->FullReport($shift, resent: true));
    }

    /**
     * A closed shift's whole report (everything paid on it, late sales included) as the Telegram
     * HTML text - what Resend() sends, and what `--preview` prints.
     */
    public function FullReport(Shift $shift, bool $resent = false): string
    {
        if ($shift->isOpen()) {
            throw new RuntimeException("Shift #{$shift->id} is still open.");
        }

        $ticketIds = $shift->tickets()->where('status', 'paid')->orderBy('id')->pluck('id')->all();

        return $this->closingMessage($shift, $ticketIds, $resent);
    }

    public function SendTest(): void
    {
        $this->send("✅ <b>EPOS is connected.</b>\nShift reports will be posted here when a shift closes.");
    }

    /**
     * Group chats the bot has recently seen a message in - for finding TELEGRAM_CHAT_ID. Only
     * needs the bot token.
     *
     * @return array<int, array{id: int|string, title: string, type: string}>
     */
    public function RecentChats(): array
    {
        $updates = $this->call('getUpdates', [])['result'] ?? [];

        return collect($updates)
            ->map(fn (array $update): ?array => ($update['message'] ?? $update['my_chat_member'] ?? $update['channel_post'] ?? [])['chat'] ?? null)
            ->filter()
            ->unique('id')
            ->map(fn (array $chat): array => [
                'id' => $chat['id'],
                'title' => $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')),
                'type' => $chat['type'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $ticketIds
     */
    private function closingMessage(Shift $shift, array $ticketIds, bool $resent = false): string
    {
        $lines = [
            '🧾 <b>Shift #'.$shift->id.' closed'.($resent ? ' (resent)' : '').'</b>',
            $this->escape($this->period($shift->opened_at, $shift->closed_at)),
        ];

        if ($shift->closedBy !== null) {
            $lines[] = 'Closed by '.$this->escape($shift->closedBy->name);
        }

        return implode("\n", [...$lines, ...$this->soldSections($ticketIds)]);
    }

    /**
     * @param  array<int, int>  $ticketIds
     */
    private function lateSalesMessage(Shift $shift, array $ticketIds): string
    {
        return implode("\n", [
            '➕ <b>Shift #'.$shift->id.' — late sales</b>',
            'Paid offline and synced after the shift closed (not in its closing totals).',
            ...$this->soldSections($ticketIds),
        ]);
    }

    /**
     * @param  array<int, int>  $ticketIds
     * @return array<int, string>
     */
    private function soldSections(array $ticketIds): array
    {
        $count = count($ticketIds);
        $lines = ['', '<b>ITEMS SOLD</b> ('.$count.' '.($count === 1 ? 'ticket' : 'tickets').')'];

        if ($count === 0) {
            return [...$lines, 'No sales.'];
        }

        $sold = $this->salesReportService->SoldOnTickets($ticketIds);

        foreach ($sold['categories'] as $category) {
            $lines[] = '';
            $lines[] = '<b>'.$this->escape($category['name']).'</b>';

            foreach ($category['items'] as $item) {
                $lines[] = $this->escape($item['name']).' — '.$item['quantity'];
            }
        }

        if ($sold['rawMaterials'] === []) {
            return $lines;
        }

        $lines[] = '';
        $lines[] = '<b>RAW MATERIALS USED</b>';

        foreach ($sold['rawMaterials'] as $group) {
            $lines[] = '';
            $lines[] = '<b>'.$this->escape($group['name']).'</b>';

            foreach ($group['items'] as $material) {
                $lines[] = $this->escape($material['name']).' — '.$this->quantity($material['quantity'], $material['unit']);
            }
        }

        return $lines;
    }

    private function period(CarbonInterface $openedAt, ?CarbonInterface $closedAt): string
    {
        $timezone = config('app.timezone');
        $opened = $openedAt->copy()->setTimezone($timezone);
        $closed = ($closedAt ?? now())->copy()->setTimezone($timezone);

        if ($opened->isSameDay($closed)) {
            return $opened->format('D, M j, Y').' · '.$opened->format('g:i A').' – '.$closed->format('g:i A');
        }

        return $opened->format('M j, g:i A').' – '.$closed->format('M j, g:i A');
    }

    /**
     * "2.25 pcs", "0.5 kg", "750 g". A thousand grams or ml or more reads as kg or L ("1.455 kg").
     */
    private function quantity(float $quantity, string $unit): string
    {
        if (in_array($unit, ['gram', 'ml'], true) && $quantity >= 1000) {
            [$quantity, $unit] = [$quantity / 1000, $unit === 'gram' ? 'kg' : 'liter'];
        }

        $number = rtrim(rtrim(number_format($quantity, 3, '.', ','), '0'), '.');
        $label = match ($unit) {
            'piece' => $quantity == 1 ? 'pc' : 'pcs',
            'gram' => 'g',
            'liter' => 'L',
            default => $unit,
        };

        return "{$number} {$label}";
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Long reports go out as several messages, split between lines (so no bold tag is cut).
     */
    private function send(string $message): void
    {
        $chunk = '';

        foreach (explode("\n", $message) as $line) {
            if ($chunk !== '' && mb_strlen($chunk."\n".$line) > self::MESSAGE_LIMIT) {
                $this->sendMessage($chunk);
                $chunk = '';
            }

            $chunk = $chunk === '' ? $line : $chunk."\n".$line;
        }

        if (trim($chunk) !== '') {
            $this->sendMessage($chunk);
        }
    }

    private function sendMessage(string $text): void
    {
        $this->call('sendMessage', [
            'chat_id' => config('services.telegram.chat_id'),
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload): array
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not set.');
        }

        try {
            $response = Http::asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (Throwable $e) {
            // Connection errors quote the URL, which holds the bot token: keep it out of the logs.
            throw new RuntimeException('Telegram could not be reached: '.str_replace($token, '***', $e->getMessage()));
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException("Telegram refused {$method}: ".($response->json('description') ?? "HTTP {$response->status()}"));
        }

        return $response->json();
    }
}
