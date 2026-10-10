<?php

namespace App\Services;

use App\Events\Kds\TicketPaid;
use App\Exceptions\ChargeAmountMismatchException;
use App\Models\Charge;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\TicketItemIngredient;
use App\Models\User;
use App\Services\Concerns\BroadcastsSafely;
use App\Services\Sync\SyncContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    use BroadcastsSafely;

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
     * Offline ($sync->offline) the money was already taken: the payment is recorded at the time it
     * happened with the receipt numbers the phone printed (`receipt_number` per charge), stock may
     * go below zero, and if the phone's total differs from the server's the difference becomes
     * the ticket's discount (SyncService flags it) instead of refusing the payment.
     *
     * @param  array<int, array{payment_method: string, amount: float, tendered_amount?: float|null, payment_reference?: string|null, client_uuid?: string|null, receipt_number?: string|null}>  $charges
     */
    public function ChargeTicket(Ticket $ticket, User $cashier, array $charges, ?SyncContext $sync = null): Ticket
    {
        if ($charges === []) {
            throw new InvalidArgumentException('At least one charge is required.');
        }

        $offline = $sync?->offline === true;

        $paid = DB::transaction(function () use ($ticket, $cashier, $charges, $sync, $offline): Ticket {
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

            // Compare in whole centavos throughout - max(0, ...) above can hand back the int 0
            // rather than the float 0.0, so a strict === 0.0 check on $total is not reliable.
            $totalCents = (int) round($total * 100);
            $sumCharges = (int) round((float) array_sum(array_column($charges, 'amount')) * 100);

            // Offline, what the customer paid stands: the gap becomes the discount, so the
            // receipts still prorate to the centavo.
            if ($offline && $sumCharges !== $totalCents) {
                $lockedTicket->update(['discount_amount' => round(($subtotal * 100 - $sumCharges) / 100, 2), 'discount_percent' => 0]);
                $total = round($sumCharges / 100, 2);
                $totalCents = $sumCharges;
            }

            // A ticket discounted to zero (a comp) still has to be closed: stock was reserved
            // and must be deducted, and the shift/receipt history needs a record of it. It's
            // closed with exactly one zero-amount charge - the sum-equals-total check below
            // can't by itself rule out e.g. two zero-amount charges against a zero total, and
            // ReceiptService's discount proration divides by the total, which a second charge
            // (index 0 of 2, not the last) would turn into a division by zero.
            if ($totalCents === 0 && count($charges) !== 1) {
                throw new InvalidArgumentException('A complimentary ticket (total ₱0) must be closed with a single zero-amount charge.');
            }

            // Require an exact match. (An earlier version tolerated 1 centavo, but receipt
            // proration needs the charges to add up to the ticket total exactly.)
            if ($sumCharges !== $totalCents) {
                throw new ChargeAmountMismatchException;
            }

            $createdCharges = collect();

            foreach ($charges as $chargeData) {
                $tendered = $chargeData['tendered_amount'] ?? null;

                $createdCharges->push(Charge::create([
                    'client_uuid' => $chargeData['client_uuid'] ?? null,
                    'ticket_id' => $lockedTicket->id,
                    'payment_method' => $chargeData['payment_method'],
                    'amount' => $chargeData['amount'],
                    'tendered_amount' => $tendered,
                    'change_due' => $tendered !== null ? round($tendered - (float) $chargeData['amount'], 2) : null,
                    'status' => 'paid',
                    'payment_reference' => $chargeData['payment_reference'] ?? null,
                    'created_by' => $cashier->id,
                    'paid_at' => $offline ? $sync->at : now(),
                ]));
            }

            foreach ($items as $ticketItem) {
                // A stockless variant line (e.g. "Lagi") never reserved stock and takes none.
                if ($ticketItem->is_stockless) {
                    continue;
                }

                $deducted = $this->inventoryService->DeductItem($ticketItem->item, $ticketItem->quantity, allowShortfall: $offline);

                // Record what a recipe line actually took, so usage reports and refunds don't
                // depend on the recipe staying the same.
                foreach ($deducted as $usage) {
                    TicketItemIngredient::create([
                        'ticket_item_id' => $ticketItem->id,
                        'ingredient_id' => $usage['ingredient']->id,
                        'quantity_used' => $usage['quantity'],
                        'unit' => $usage['ingredient']->unit,
                        'cost_per_unit' => $usage['ingredient']->cost_per_unit,
                    ]);
                }
            }

            $lockedTicket->update([
                'subtotal' => $subtotal,
                'total' => $total,
                'status' => 'paid',
                'closed_at' => $offline ? $sync->at : now(),
            ]);

            // Same transaction as the payment: if receipt generation fails, the whole
            // payment (charges, inventory deduction, paid status) rolls back with it.
            $this->receiptService->GenerateReceipts(
                $lockedTicket,
                $createdCharges,
                $cashier,
                $offline ? $sync : null,
                $offline ? array_column($charges, 'receipt_number') : [],
            );

            return $lockedTicket->fresh(['charges.receipt']);
        });

        $this->broadcastSafely(fn () => broadcast(new TicketPaid($paid)));

        return $paid;
    }
}
