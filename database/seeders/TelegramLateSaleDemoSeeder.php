<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Modifier;
use App\Models\Shift;
use App\Models\User;
use App\Services\PosDeviceService;
use App\Services\SyncService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo for the Telegram "late sales" follow-up: a phone that lost Wi-Fi near the end of the
 * last closed shift syncs two sales only now, through the real offline sync (POST /sync's
 * service). Run after TelegramDemoSeeder and after its report went out:
 *
 *   php artisan db:seed --class=TelegramLateSaleDemoSeeder
 *   php artisan telegram:shift-reports
 */
class TelegramLateSaleDemoSeeder extends Seeder
{
    public function run(SyncService $sync, PosDeviceService $devices): void
    {
        $shift = Shift::query()->where('status', 'closed')->latest('closed_at')->first()
            ?? throw new RuntimeException('No closed shift yet - run TelegramDemoSeeder first.');
        $cashier = User::query()->where('username', 'dangbi')->firstOrFail();
        $item = fn (string $name): Item => Item::query()->where('name', $name)->firstOrFail();
        $lagi = Modifier::query()->where('name', 'Lagi')->firstOrFail();

        $token = $cashier->createToken('POS-02 (demo)');
        $device = $devices->RegisterDevice($cashier, $token->accessToken);

        // Inside the shift's hours, so the phone's offline shift joins it.
        $at = fn (int $minutesBeforeClose): string => $shift->closed_at->copy()->subMinutes($minutesBeforeClose)->toIso8601String();
        $action = fn (string $type, array $data, int $minutesBeforeClose): array => [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'offline' => true,
            'happened_at' => $at($minutesBeforeClose),
            'data' => $data,
        ];

        $phoneShift = (string) Str::uuid();
        $actions = [$action('shift.open', ['shift_uuid' => $phoneShift, 'starting_cash' => 0], 50)];

        $sales = [
            ['Table 6', 45, [[$item('Fried Itik'), 2, []], [$item('Red Horse'), 3, []], [$item('Garlic Rice'), 2, []]]],
            ['nonoy', 35, [[$item('Adobong Itik'), 1, [$lagi->id]], [$item('Sisig Itik'), 1, []], [$item('Plain Rice'), 1, []]]],
        ];

        foreach ($sales as [$customer, $minutes, $lines]) {
            $ticket = (string) Str::uuid();
            $total = 0;
            $actions[] = $action('ticket.create', ['ticket_uuid' => $ticket, 'shift_uuid' => $phoneShift, 'terminal_id' => 'POS-02', 'customer_name' => $customer, 'order_type' => 'dine_in'], $minutes);

            foreach ($lines as [$menuItem, $quantity, $modifierIds]) {
                $total += (float) $menuItem->base_price * $quantity;
                $actions[] = $action('ticket.add_item', [
                    'ticket_uuid' => $ticket,
                    'line_uuid' => (string) Str::uuid(),
                    'item_id' => $menuItem->id,
                    'quantity' => $quantity,
                    'unit_price' => (float) $menuItem->base_price,
                    'modifier_ids' => $modifierIds,
                ], $minutes);
            }

            $actions[] = $action('ticket.charge', ['ticket_uuid' => $ticket, 'charges' => [
                ['charge_uuid' => (string) Str::uuid(), 'payment_method' => 'cash', 'amount' => $total, 'tendered_amount' => $total],
            ]], $minutes - 10);
        }

        $results = collect($sync->Sync($cashier, $device, $actions, 0));
        $rejected = $results->where('status', 'rejected');

        if ($rejected->isNotEmpty()) {
            throw new RuntimeException('Sync rejected: '.$rejected->pluck('message')->filter()->implode('; '));
        }

        $this->command?->info("Synced 2 late offline sales onto closed shift #{$shift->id} from {$device->code}.");
    }
}
