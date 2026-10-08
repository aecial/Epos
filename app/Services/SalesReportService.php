<?php

namespace App\Services;

use App\Models\RefundItem;
use App\Models\ShiftTransaction;
use App\Models\Ticket;
use App\Models\TicketItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What was sold in a period and what it earned. A line counts as sold when its ticket was paid
 * (closed_at) in the period - whichever shift it belongs to, so an open shift's paid tickets show
 * live. Merged sources are never counted (their lines moved to the paid target); open and
 * cancelled tickets and voided lines aren't sold. Refunds count on the day they were approved.
 * Money is worked in whole centavos so every total adds up exactly.
 */
class SalesReportService
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, float|int>}
     */
    public function ItemsSold(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];

        foreach ($this->soldLines($from, $to)->groupBy('ticket_id') as $lines) {
            foreach ($this->withDiscountShares($lines) as [$line, $discountCents]) {
                $row = &$this->rowFor($rows, $line);
                $lineCents = $this->toCents($line->line_total);

                $row['quantity'] += (int) $line->quantity;
                $row['gross_cents'] += $lineCents;
                $row['discount_cents'] += $discountCents;
                $row['cost_cents'] += $this->toCents($line->item_cost_price) * (int) $line->quantity;
                unset($row);
            }
        }

        foreach ($this->approvedRefundItems($from, $to) as $refundItem) {
            $row = &$this->rowFor($rows, $refundItem->ticketItem);
            $row['refunded_quantity'] += (int) $refundItem->quantity;
            $row['refunded_cents'] += $this->toCents($refundItem->amount);
            unset($row);
        }

        $presented = collect($rows)
            ->map(fn (array $row): array => $this->presentRow($row))
            ->sortByDesc('net_sales')
            ->values();

        $grossProfitCents = (int) collect($rows)->sum(fn (array $row): int => $this->profitCents($row));
        $expensesCents = $this->toCents(
            ShiftTransaction::query()
                ->where('type', 'expense')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$from, $to])
                ->sum('amount')
        );

        return [
            'rows' => $presented->all(),
            'summary' => [
                'tickets_paid' => Ticket::query()->where('status', 'paid')->whereBetween('closed_at', [$from, $to])->count(),
                // Fees aren't dishes, so they're left out of the item count (their money isn't).
                'items_sold' => (int) $presented->where('line_type', '!=', 'fee')->sum('quantity'),
                'gross_sales' => $this->toPesos(collect($rows)->sum('gross_cents')),
                'discounts' => $this->toPesos(collect($rows)->sum('discount_cents')),
                'net_sales' => $this->toPesos(collect($rows)->sum(fn (array $row): int => $row['gross_cents'] - $row['discount_cents'])),
                'refunds' => $this->toPesos(collect($rows)->sum('refunded_cents')),
                'cost' => $this->toPesos(collect($rows)->sum('cost_cents')),
                'gross_profit' => $this->toPesos($grossProfitCents),
                'expenses' => $this->toPesos($expensesCents),
                'net_profit' => $this->toPesos($grossProfitCents - $expensesCents),
            ],
        ];
    }

    /**
     * Tickets still open right now - ordered but not yet paid, so not sold.
     *
     * @return array{count: int, total: float}
     */
    public function OpenTickets(): array
    {
        $open = Ticket::query()->where('status', 'open');

        return [
            'count' => (clone $open)->count(),
            'total' => round((float) $open->sum('total'), 2),
        ];
    }

    /**
     * @return Collection<int, TicketItem>
     */
    private function soldLines(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return TicketItem::query()
            ->join('tickets', 'tickets.id', '=', 'ticket_items.ticket_id')
            ->where('tickets.status', 'paid')
            ->whereBetween('tickets.closed_at', [$from, $to])
            ->whereNull('ticket_items.voided_at')
            ->orderBy('ticket_items.ticket_id')
            ->orderBy('ticket_items.id')
            ->get([
                'ticket_items.id', 'ticket_items.ticket_id', 'ticket_items.item_id', 'ticket_items.item_name',
                'ticket_items.line_type', 'ticket_items.quantity', 'ticket_items.line_total', 'ticket_items.item_cost_price',
                'tickets.subtotal as ticket_subtotal', 'tickets.total as ticket_total',
            ]);
    }

    /**
     * @return Collection<int, RefundItem>
     */
    private function approvedRefundItems(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return RefundItem::query()
            ->whereHas('refund', fn ($query) => $query->where('status', 'approved')->whereBetween('approved_at', [$from, $to]))
            ->with('ticketItem:id,item_id,item_name,line_type')
            ->get();
    }

    /**
     * Each line's share of its ticket's discount, in centavos. Shares follow the line's part of
     * the subtotal and the last line takes the remainder, so a ticket's shares always add up to
     * its discount exactly (the same proration idea as receipts).
     *
     * @param  Collection<int, TicketItem>  $lines  one ticket's lines
     * @return array<int, array{0: TicketItem, 1: int}>
     */
    private function withDiscountShares(Collection $lines): array
    {
        $first = $lines->first();
        $subtotalCents = $this->toCents($first->ticket_subtotal);
        $discountCents = max(0, $subtotalCents - $this->toCents($first->ticket_total));
        $remaining = $discountCents;
        $lastIndex = $lines->count() - 1;
        $shares = [];

        foreach ($lines->values() as $index => $line) {
            $share = $subtotalCents === 0 ? 0 : ($index === $lastIndex
                ? $remaining
                : intdiv($discountCents * $this->toCents($line->line_total), $subtotalCents));
            $remaining -= $share;
            $shares[] = [$line, $share];
        }

        return $shares;
    }

    /**
     * The row a line belongs to, created on first use: one per menu item, but one per typed name
     * for custom lines (each is a different thing the cashier sold).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function &rowFor(array &$rows, TicketItem $line): array
    {
        $key = $line->line_type === 'custom' ? "custom:{$line->item_name}" : "item:{$line->item_id}";

        $rows[$key] ??= [
            'key' => $key,
            'item_id' => $line->item_id,
            'name' => $line->item_name,
            'line_type' => $line->line_type,
            'quantity' => 0,
            'gross_cents' => 0,
            'discount_cents' => 0,
            'refunded_quantity' => 0,
            'refunded_cents' => 0,
            'cost_cents' => 0,
        ];

        return $rows[$key];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function profitCents(array $row): int
    {
        return $row['gross_cents'] - $row['discount_cents'] - $row['refunded_cents'] - $row['cost_cents'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentRow(array $row): array
    {
        $netSalesCents = $row['gross_cents'] - $row['discount_cents'];
        $earnedCents = $netSalesCents - $row['refunded_cents'];
        $profitCents = $this->profitCents($row);

        return [
            'key' => $row['key'],
            'item_id' => $row['item_id'],
            'name' => $row['name'],
            'line_type' => $row['line_type'],
            'quantity' => $row['quantity'],
            'gross_sales' => $this->toPesos($row['gross_cents']),
            'discount' => $this->toPesos($row['discount_cents']),
            'net_sales' => $this->toPesos($netSalesCents),
            'refunded_quantity' => $row['refunded_quantity'],
            'refunded' => $this->toPesos($row['refunded_cents']),
            'cost' => $this->toPesos($row['cost_cents']),
            'profit' => $this->toPesos($profitCents),
            'margin' => $earnedCents > 0 ? round($profitCents / $earnedCents * 100, 1) : null,
            // A dish sold with no cost recorded would show as pure profit - flag it instead.
            'missing_cost' => $row['line_type'] === 'item' && $row['quantity'] > 0 && $row['cost_cents'] === 0,
        ];
    }

    private function toCents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function toPesos(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
