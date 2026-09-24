<?php

namespace App\Services;

use App\Exceptions\ChargeAmountMismatchException;
use App\Models\Charge;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(
        private InventoryService $inventoryService,
        private ReceiptService $receiptService,
    ) {}

    /**
     * Charges are amounts-only (no item assignment / charge_items) — each charge
     * covers a portion of the ticket total, and every receipt printed from a charge
     * lists all ticket items with a prorated discount. Deducts inventory, marks the
     * ticket paid, and returns it with its charges loaded.
     *
     * @param  array<int, array{payment_method: string, amount: float, tendered_amount?: float|null, payment_reference?: string|null}>  $charges
     */
    public function ChargeTicket(Ticket $ticket, User $cashier, array $charges): Ticket
    {
        if ($charges === []) {
            throw new InvalidArgumentException('At least one charge is required.');
        }

        return DB::transaction(function () use ($ticket, $cashier, $charges): Ticket {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $lockedTicket->isOpen()) {
                throw new InvalidArgumentException('Ticket is not open.');
            }

            $items = TicketItem::query()
                ->where('ticket_id', $lockedTicket->id)
                ->whereNull('voided_at')
                ->with('item')
                ->get();

            if ($items->isEmpty()) {
                throw new InvalidArgumentException('Cannot charge a ticket with no items.');
            }

            // Recalculate from live, non-voided lines - never trust a client-supplied
            // total, another terminal may have voided an item since it was last read.
            $subtotal = round((float) $items->sum('line_total'), 2);
            $discountPercent = (float) $lockedTicket->discount_percent;
            $discountAmount = (float) $lockedTicket->discount_amount;
            $effectiveDiscount = $discountPercent > 0
                ? round($subtotal * $discountPercent / 100, 2)
                : $discountAmount;
            $total = max(0, round($subtotal - $effectiveDiscount, 2));

            // Compare in whole centavos and require an exact match. (An earlier version
            // tolerated 1 centavo, but receipt proration needs the charges to add up to
            // the ticket total exactly.)
            $sumCharges = (int) round((float) array_sum(array_column($charges, 'amount')) * 100);

            if ($sumCharges !== (int) round($total * 100)) {
                throw new ChargeAmountMismatchException;
            }

            $createdCharges = collect();

            foreach ($charges as $chargeData) {
                $tendered = $chargeData['tendered_amount'] ?? null;

                $createdCharges->push(Charge::create([
                    'ticket_id' => $lockedTicket->id,
                    'payment_method' => $chargeData['payment_method'],
                    'amount' => $chargeData['amount'],
                    'tendered_amount' => $tendered,
                    'change_due' => $tendered !== null ? round($tendered - (float) $chargeData['amount'], 2) : null,
                    'status' => 'paid',
                    'payment_reference' => $chargeData['payment_reference'] ?? null,
                    'created_by' => $cashier->id,
                    'paid_at' => now(),
                ]));
            }

            foreach ($items as $ticketItem) {
                $this->inventoryService->DeductItem($ticketItem->item, $ticketItem->quantity);
            }

            $lockedTicket->update([
                'subtotal' => $subtotal,
                'total' => $total,
                'status' => 'paid',
                'closed_at' => now(),
            ]);

            // Same transaction as the payment: if receipt generation fails, the whole
            // payment (charges, inventory deduction, paid status) rolls back with it.
            $this->receiptService->GenerateReceipts($lockedTicket, $createdCharges, $cashier);

            return $lockedTicket->fresh(['charges.receipt']);
        });
    }
}
