<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Receipt;
use App\Models\ReceiptPrint;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReceiptService
{
    /**
     * Issue one receipt per paid charge. Must be called inside the payment transaction so
     * that a receipt exists if and only if the payment committed.
     *
     * @param  Collection<int, Charge>  $charges
     * @return Collection<int, Receipt>
     */
    public function GenerateReceipts(Ticket $ticket, Collection $charges, User $issuedBy): Collection
    {
        // Receipt numbers are a per-day running sequence (REC-2026-09-24-001). Locking the
        // shift row serialises every payment in the shift, so "max + 1" can't be read by
        // two payments at once. The unique (receipt_date, sequence) index is the backstop.
        Shift::query()->lockForUpdate()->findOrFail($ticket->shift_id);

        $lines = TicketItem::query()
            ->where('ticket_id', $ticket->id)
            ->whereNull('voided_at')
            ->with('modifiers')
            ->orderBy('id')
            ->get();

        // Receipts print names/prices only. `notes` is deliberately never read here:
        // notes are KDS-only and must not reach a customer receipt.
        $itemLines = $lines->map(fn (TicketItem $line): array => [
            'name' => $line->item_name,
            'quantity' => (int) $line->quantity,
            'unit_price' => (float) $line->unit_price,
            'modifiers' => $line->modifiers
                ->map(fn ($modifier): array => ['name' => $modifier->name, 'price' => (float) $modifier->price])
                ->values()
                ->all(),
            'line_total' => (float) $line->line_total,
        ])->values()->all();

        // Sources merged into this ticket (kept flat by MergeTickets), so one receipt can
        // show "#001, #002".
        $mergedFrom = Ticket::query()
            ->where('merged_into_ticket_id', $ticket->id)
            ->orderBy('id')
            ->get(['order_number', 'customer_name'])
            ->map(fn (Ticket $source): array => [
                'order_number' => $source->order_number,
                'customer_name' => $source->customer_name,
            ])
            ->values()
            ->all();

        $totalCents = $this->toCents($ticket->total);
        $discountCents = $this->toCents($ticket->subtotal) - $totalCents;

        if ($totalCents <= 0) {
            throw new InvalidArgumentException('Cannot issue a receipt for a ticket with no payable total.');
        }

        $issuedAt = now();
        $date = $issuedAt->toDateString();
        $sequence = (int) Receipt::query()->where('receipt_date', $date)->max('sequence');

        $remainingDiscount = $discountCents;
        $lastIndex = $charges->count() - 1;
        $receipts = collect();

        foreach ($charges->values() as $index => $charge) {
            $amountCents = $this->toCents($charge->amount);

            // Prorate the ticket discount across charges in whole centavos. Every charge but
            // the last gets its floored share; the last takes the remainder, so the shares
            // always sum to the ticket discount exactly (no lost or invented centavo).
            $discountShare = $index === $lastIndex
                ? $remainingDiscount
                : intdiv($discountCents * $amountCents, $totalCents);
            $remainingDiscount -= $discountShare;

            $sequence++;
            $receiptNumber = sprintf('REC-%s-%03d', $date, $sequence);

            $payload = [
                'receipt_number' => $receiptNumber,
                'issued_at' => $issuedAt->toIso8601String(),
                'order' => [
                    'order_number' => $ticket->order_number,
                    'customer_name' => $ticket->customer_name,
                    'order_type' => $ticket->order_type,
                    'terminal_id' => $ticket->terminal_id,
                    'merged_from' => $mergedFrom,
                ],
                'cashier' => $issuedBy->name,
                'items' => $itemLines,
                // This charge's slice of the bill: subtotal - discount = what it paid.
                'subtotal' => round(($amountCents + $discountShare) / 100, 2),
                'discount' => round($discountShare / 100, 2),
                'total' => round($amountCents / 100, 2),
                'payment' => [
                    'method' => $charge->payment_method,
                    'amount' => round($amountCents / 100, 2),
                    'tendered_amount' => $charge->tendered_amount !== null ? (float) $charge->tendered_amount : null,
                    'change_due' => $charge->change_due !== null ? (float) $charge->change_due : null,
                    'reference' => $charge->payment_reference,
                ],
            ];

            $receipt = Receipt::create([
                'charge_id' => $charge->id,
                'ticket_id' => $ticket->id,
                'shift_id' => $ticket->shift_id,
                'terminal_id' => $ticket->terminal_id,
                'receipt_date' => $date,
                'sequence' => $sequence,
                'receipt_number' => $receiptNumber,
                'order_number' => $ticket->order_number,
                'customer_name' => $ticket->customer_name,
                'payment_method' => $charge->payment_method,
                'amount' => round($amountCents / 100, 2),
                'payload' => $payload,
                'issued_by' => $issuedBy->id,
                'issued_at' => $issuedAt,
            ]);

            ReceiptPrint::create([
                'receipt_id' => $receipt->id,
                'printed_by' => $issuedBy->id,
                'is_reprint' => false,
                'printed_at' => $issuedAt,
            ]);

            $receipts->push($receipt);
        }

        return $receipts;
    }

    /**
     * Lightweight history rows (no payload). No terminal restriction: every terminal can
     * browse every receipt; terminal_id is just an optional filter.
     *
     * @param  array{shift_id?: int, ticket_id?: int, terminal_id?: string, date_from?: string, date_to?: string, payment_method?: string, search?: string}  $filters
     */
    public function ReadAllReceipt(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Receipt::query()
            ->select([
                'id', 'receipt_number', 'order_number', 'customer_name', 'payment_method',
                'amount', 'terminal_id', 'shift_id', 'ticket_id', 'issued_at',
            ])
            ->withCount(['prints as reprint_count' => fn ($query) => $query->where('is_reprint', true)])
            ->when($filters['shift_id'] ?? null, fn ($query, $value) => $query->where('shift_id', $value))
            ->when($filters['ticket_id'] ?? null, fn ($query, $value) => $query->where('ticket_id', $value))
            ->when($filters['terminal_id'] ?? null, fn ($query, $value) => $query->where('terminal_id', $value))
            ->when($filters['payment_method'] ?? null, fn ($query, $value) => $query->where('payment_method', $value))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('issued_at', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('issued_at', '<=', $value))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(
                fn ($inner) => $inner
                    ->where('order_number', 'like', "%{$value}%")
                    ->orWhere('customer_name', 'like', "%{$value}%")
                    ->orWhere('receipt_number', 'like', "%{$value}%")
            ))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function ReadReceipt(Receipt $receipt): Receipt
    {
        return $receipt
            ->loadCount(['prints as reprint_count' => fn ($query) => $query->where('is_reprint', true)])
            ->load(['prints' => fn ($query) => $query->orderBy('id'), 'prints.printedBy:id,name']);
    }

    /**
     * Log a duplicate print. The receipt itself is never touched; the caller adds the
     * "DUPLICATE RECEIPT" watermark when rendering.
     */
    public function ReprintReceipt(Receipt $receipt, User $printedBy): Receipt
    {
        DB::transaction(function () use ($receipt, $printedBy): void {
            ReceiptPrint::create([
                'receipt_id' => $receipt->id,
                'printed_by' => $printedBy->id,
                'is_reprint' => true,
                'printed_at' => now(),
            ]);
        });

        return $this->ReadReceipt($receipt->refresh());
    }

    private function toCents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
