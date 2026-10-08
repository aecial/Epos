<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RefundService
{
    public function __construct(
        private InventoryService $inventoryService,
        private PasscodeService $passcodeService,
    ) {}

    /**
     * A refund always targets one charge, so cash-vs-gcash is known for shift cash
     * reconciliation. Item-level so a single dish can be refunded off a larger ticket.
     *
     * @param  array<int, array{ticket_item_id: int, quantity: int, amount: float}>  $items
     */
    public function RequestRefund(Ticket $ticket, Charge $charge, User $requestedBy, array $items, ?string $reason = null): Refund
    {
        if ($items === []) {
            throw new InvalidArgumentException('At least one item is required.');
        }

        if ((int) $charge->ticket_id !== (int) $ticket->id) {
            throw new InvalidArgumentException('Charge does not belong to this ticket.');
        }

        if ($ticket->status !== 'paid') {
            throw new InvalidArgumentException('Only paid tickets can be refunded.');
        }

        return DB::transaction(function () use ($ticket, $charge, $requestedBy, $items, $reason): Refund {
            $amount = round((float) array_sum(array_column($items, 'amount')), 2);

            // Lock the charge so two concurrent refund requests against it can't both pass
            // the "doesn't exceed what was collected" check before either one commits.
            $lockedCharge = Charge::query()->lockForUpdate()->findOrFail($charge->id);

            $alreadyRefundedForChargeCents = (int) round(
                (float) Refund::query()
                    ->where('charge_id', $lockedCharge->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->sum('amount') * 100
            );
            $newRefundCents = (int) round($amount * 100);
            $chargeCents = (int) round((float) $lockedCharge->amount * 100);

            if ($alreadyRefundedForChargeCents + $newRefundCents > $chargeCents) {
                throw new InvalidArgumentException('Refund amount exceeds what this charge collected.');
            }

            foreach ($items as $item) {
                // Lock the ticket item so two concurrent refund requests against the same
                // line can't both pass the remaining-quantity/amount checks below.
                $ticketItem = TicketItem::query()->lockForUpdate()->find($item['ticket_item_id']);

                if (! $ticketItem || (int) $ticketItem->ticket_id !== (int) $ticket->id) {
                    throw new InvalidArgumentException("Ticket item {$item['ticket_item_id']} does not belong to this ticket.");
                }

                $alreadyRefundedQuantity = (int) RefundItem::query()
                    ->where('ticket_item_id', $ticketItem->id)
                    ->whereHas('refund', fn ($query) => $query->whereIn('status', ['pending', 'approved']))
                    ->sum('quantity');

                $remainingQuantity = $ticketItem->quantity - $alreadyRefundedQuantity;

                if ($item['quantity'] > $remainingQuantity) {
                    throw new InvalidArgumentException("Refund quantity for ticket item {$ticketItem->id} exceeds the remaining refundable quantity ({$remainingQuantity}).");
                }

                // What one unit of this line is actually worth, derived from what was charged
                // (line_total already includes modifiers), never from client input.
                $unitPriceCents = $ticketItem->quantity > 0
                    ? (int) round(((float) $ticketItem->line_total * 100) / $ticketItem->quantity)
                    : 0;
                $maxAmountCents = $unitPriceCents * $item['quantity'];
                $requestedAmountCents = (int) round($item['amount'] * 100);

                if ($requestedAmountCents > $maxAmountCents) {
                    throw new InvalidArgumentException("Refund amount for ticket item {$ticketItem->id} exceeds its purchased value.");
                }
            }

            $refund = Refund::create([
                'shift_id' => $ticket->shift_id,
                'ticket_id' => $ticket->id,
                'charge_id' => $charge->id,
                'requested_by' => $requestedBy->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            foreach ($items as $item) {
                RefundItem::create([
                    'refund_id' => $refund->id,
                    'ticket_item_id' => $item['ticket_item_id'],
                    'quantity' => $item['quantity'],
                    'amount' => $item['amount'],
                ]);
            }

            return $refund->fresh(['items']);
        });
    }

    /**
     * $passcode must belong to exactly one active admin/manager, who is recorded as the
     * approver, per CLAUDE.md's "passcode required to approve refund" rule. $operator is
     * the logged-in user at the terminal; failed passcode attempts are throttled per them.
     */
    public function ApproveRefund(Refund $refund, User $operator, string $passcode): Refund
    {
        $approver = $this->passcodeService->ResolveApprover($operator, $passcode);

        return DB::transaction(function () use ($refund, $approver): Refund {
            $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($lockedRefund->status !== 'pending') {
                throw new InvalidArgumentException('Refund has already been decided.');
            }

            $items = RefundItem::query()
                ->where('refund_id', $lockedRefund->id)
                ->with('ticketItem.item')
                ->get();

            foreach ($items as $refundItem) {
                // Return what the sale actually took; lines paid before usage was recorded
                // fall back to the item's current recipe.
                if (! $this->inventoryService->RestoreRecordedUsage($refundItem->ticketItem, $refundItem->quantity)) {
                    $this->inventoryService->RestoreItem($refundItem->ticketItem->item, $refundItem->quantity);
                }
            }

            $lockedRefund->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $lockedRefund;
        });
    }

    /**
     * Same passcode gate as ApproveRefund; the deciding admin/manager is stored in approved_by.
     */
    public function RejectRefund(Refund $refund, User $operator, string $passcode): Refund
    {
        $approver = $this->passcodeService->ResolveApprover($operator, $passcode);

        return DB::transaction(function () use ($refund, $approver): Refund {
            $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($lockedRefund->status !== 'pending') {
                throw new InvalidArgumentException('Refund has already been decided.');
            }

            $lockedRefund->update([
                'status' => 'rejected',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $lockedRefund;
        });
    }
}
