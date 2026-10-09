<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Ingredient;
use App\Models\Item;
use App\Models\Refund;
use App\Models\Ticket;
use Carbon\CarbonInterface;

/**
 * Everything the back-office dashboard shows, built from the same services as the report pages
 * so the numbers always agree with them: the open shift, today so far against the same point
 * yesterday, sales by hour, the payment mix, top items, what needs attention, and the last 7
 * days. A sale counts when its ticket is paid (closed_at), like everywhere else.
 */
class DashboardService
{
    /** A kitchen order waiting at least this long is flagged. */
    public const KITCHEN_LATE_MINUTES = 20;

    public function __construct(
        private SalesReportService $salesReportService,
        private ShiftService $shiftService,
        private KdsService $kdsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Build(CarbonInterface $now): array
    {
        $todayStart = $now->copy()->startOfDay();
        $today = $this->salesReportService->ItemsSold($todayStart, $now);
        // Up to the same clock time yesterday, so a 2 PM dashboard compares 2 PM with 2 PM.
        $yesterdaySoFar = $this->salesReportService->ItemsSold($todayStart->copy()->subDay(), $now->copy()->subDay());

        return [
            'now' => $now->toIso8601String(),
            'shift' => $this->shift(),
            'today' => $this->headline($today['summary']),
            'yesterday' => $this->headline($yesterdaySoFar['summary']),
            'hourly' => $this->hourly($todayStart),
            'paymentMix' => $this->paymentMix($todayStart, $now),
            'topItems' => collect($today['rows'])
                ->where('line_type', '!=', 'fee')
                ->sortByDesc('quantity')
                ->take(5)
                ->map(fn (array $row): array => ['name' => $row['name'], 'quantity' => $row['quantity'], 'net_sales' => $row['net_sales']])
                ->values(),
            'attention' => $this->attention($now, $today['rows']),
            'lastSevenDays' => $this->lastSevenDays($now),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shift(): ?array
    {
        $shift = $this->shiftService->ActiveShift()?->load('openedBy:id,name');

        if ($shift === null) {
            return null;
        }

        return [
            'id' => $shift->id,
            'opened_at' => $shift->opened_at,
            'opened_by' => $shift->openedBy?->name,
            'starting_cash' => (float) $shift->starting_cash,
            'expected_cash' => $this->shiftService->ComputeTotals($shift)['expected_cash'],
            'open_tickets' => $this->salesReportService->OpenTickets(),
        ];
    }

    /**
     * @param  array<string, float|int>  $summary  an ItemsSold() summary
     * @return array<string, float|int>
     */
    private function headline(array $summary): array
    {
        return [
            'net_sales' => $summary['net_sales'],
            'tickets_paid' => $summary['tickets_paid'],
            'average_ticket' => $summary['tickets_paid'] > 0 ? round($summary['net_sales'] / $summary['tickets_paid'], 2) : 0.0,
            'gross_profit' => $summary['gross_profit'],
            'margin' => $summary['net_sales'] - $summary['refunds'] > 0
                ? round($summary['gross_profit'] / ($summary['net_sales'] - $summary['refunds']) * 100, 1)
                : null,
            'net_profit' => $summary['net_profit'],
            'refunds' => $summary['refunds'],
        ];
    }

    /**
     * Net sales per hour (a paid ticket's total, by when it was paid) for today and all of
     * yesterday, keeping only hours with a sale on either day.
     *
     * @return array<int, array{hour: int, today: float, yesterday: float}>
     */
    private function hourly(CarbonInterface $todayStart): array
    {
        $yesterdayStart = $todayStart->copy()->subDay();
        $buckets = [];

        $paid = Ticket::query()
            ->where('status', 'paid')
            ->where('closed_at', '>=', $yesterdayStart)
            ->where('closed_at', '<', $todayStart->copy()->addDay())
            ->get(['total', 'closed_at']);

        foreach ($paid as $ticket) {
            $day = $ticket->closed_at->greaterThanOrEqualTo($todayStart) ? 'today' : 'yesterday';
            $hour = (int) $ticket->closed_at->format('G');
            $buckets[$hour] ??= ['hour' => $hour, 'today' => 0.0, 'yesterday' => 0.0];
            $buckets[$hour][$day] = round($buckets[$hour][$day] + (float) $ticket->total, 2);
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * @return array{cash: float, gcash: float}
     */
    private function paymentMix(CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = Charge::query()
            ->join('tickets', 'tickets.id', '=', 'charges.ticket_id')
            ->where('charges.status', 'paid')
            ->where('tickets.status', 'paid')
            ->whereBetween('tickets.closed_at', [$from, $to])
            ->selectRaw('charges.payment_method, sum(charges.amount) as total')
            ->groupBy('charges.payment_method')
            ->pluck('total', 'payment_method');

        return [
            'cash' => round((float) ($totals['cash'] ?? 0), 2),
            'gcash' => round((float) ($totals['gcash'] ?? 0), 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $todayRows  today's ItemsSold() rows
     * @return array<string, mixed>
     */
    private function attention(CarbonInterface $now, array $todayRows): array
    {
        $oldestPending = Refund::query()->where('status', 'pending')->oldest('requested_at')->first(['requested_at']);
        $lateCutoff = $now->copy()->subMinutes(self::KITCHEN_LATE_MINUTES);
        $lateOrders = collect($this->kdsService->GetOpenOrders())
            ->filter(fn (array $order): bool => $order['created_at']->lessThanOrEqualTo($lateCutoff));

        return [
            'pending_refunds' => [
                'count' => Refund::query()->where('status', 'pending')->count(),
                'oldest_requested_at' => $oldestPending?->requested_at,
            ],
            'late_kitchen_orders' => [
                'count' => $lateOrders->count(),
                'oldest_created_at' => $lateOrders->min('created_at'),
            ],
            'running_low' => $this->runningLow(),
            'missing_cost' => collect($todayRows)->where('missing_cost', true)->pluck('name')->values(),
        ];
    }

    /**
     * Raw materials and direct-stock items at or below their reorder level.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runningLow(): array
    {
        $ingredients = Ingredient::query()
            ->whereNotNull('reorder_level')
            ->orderBy('name')
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->isRunningLow())
            ->map(fn (Ingredient $ingredient): array => [
                'kind' => 'raw material',
                'name' => $ingredient->name,
                'unit' => $ingredient->unit,
                'available' => round((float) $ingredient->quantity - (float) $ingredient->reserved_quantity, 3),
                'reorder_level' => (float) $ingredient->reorder_level,
                'url' => route('ingredients.edit', $ingredient),
            ]);

        $items = Item::query()
            ->where('inventory_type', 'direct')
            ->whereNotNull('reorder_level')
            ->orderBy('name')
            ->get()
            ->filter(fn (Item $item): bool => $item->isRunningLow())
            ->map(fn (Item $item): array => [
                'kind' => 'item',
                'name' => $item->name,
                'unit' => null,
                'available' => (int) $item->quantity - (int) $item->reserved_quantity,
                'reorder_level' => (int) $item->reorder_level,
                'url' => route('items.edit', $item),
            ]);

        return $ingredients->concat($items)->values()->all();
    }

    /**
     * Net sales and net profit for each of the last 7 days, oldest first (today up to now).
     *
     * @return array<int, array{date: string, net_sales: float, net_profit: float}>
     */
    private function lastSevenDays(CarbonInterface $now): array
    {
        return collect(range(6, 0))->map(function (int $daysAgo) use ($now): array {
            $day = $now->copy()->subDays($daysAgo);
            $summary = $this->salesReportService->ItemsSold(
                $day->copy()->startOfDay(),
                $daysAgo === 0 ? $now : $day->copy()->endOfDay(),
            )['summary'];

            return [
                'date' => $day->toDateString(),
                'net_sales' => $summary['net_sales'],
                'net_profit' => $summary['net_profit'],
            ];
        })->all();
    }
}
