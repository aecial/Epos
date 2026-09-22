<?php

namespace App\Services;

use App\Exceptions\InvalidPasscodeException;
use App\Exceptions\NoActiveShiftException;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\TicketItemModifier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class TicketService
{
    public function __construct(private InventoryService $inventoryService) {}

    public function CreateTicket(Shift $shift, User $createdBy, string $terminalId, string $customerName, string $orderType): Ticket
    {
        return DB::transaction(function () use ($shift, $createdBy, $terminalId, $customerName, $orderType): Ticket {
            // Locking the shift row serializes order-number and name assignment
            // across concurrent terminals for the duration of this transaction.
            $lockedShift = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $lockedShift->isOpen()) {
                throw new NoActiveShiftException;
            }

            $orderNumber = $this->nextOrderNumber($lockedShift);
            $name = $this->nextAvailableName($lockedShift, trim($customerName));

            return Ticket::create([
                'shift_id' => $lockedShift->id,
                'created_by' => $createdBy->id,
                'terminal_id' => $terminalId,
                'customer_name' => $name,
                'order_number' => $orderNumber,
                'order_type' => $orderType,
                'status' => 'open',
                'subtotal' => 0,
                'total' => 0,
            ]);
        });
    }

    /**
     * @param  array<int, int>  $modifierIds
     */
    public function AddItem(Ticket $ticket, Item $item, int $quantity, array $modifierIds = [], ?string $notes = null): TicketItem
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($ticket, $item, $quantity, $modifierIds, $notes): TicketItem {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $lockedTicket->isOpen()) {
                throw new InvalidArgumentException('Cannot add items to a ticket that is not open.');
            }

            $this->inventoryService->ReserveItem($item, $quantity);

            $itemModifiers = $modifierIds === []
                ? collect()
                : $item->modifiers()->whereIn('modifiers.id', $modifierIds)->get();

            $unitPrice = (float) $item->base_price;
            $modifierTotal = (float) $itemModifiers->sum(fn (Modifier $modifier): float => (float) $modifier->pivot->price_modifier);
            $lineTotal = round(($unitPrice + $modifierTotal) * $quantity, 2);

            $ticketItem = TicketItem::create([
                'ticket_id' => $lockedTicket->id,
                'item_id' => $item->id,
                'item_name' => $item->name,
                'item_cost_price' => $item->cost_price,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'notes' => $notes,
                'line_total' => $lineTotal,
            ]);

            foreach ($itemModifiers as $modifier) {
                TicketItemModifier::create([
                    'ticket_item_id' => $ticketItem->id,
                    'modifier_id' => $modifier->id,
                    'name' => $modifier->name,
                    'price' => $modifier->pivot->price_modifier,
                ]);
            }

            $this->recalculateTotals($lockedTicket);

            return $ticketItem->refresh();
        });
    }

    /**
     * Void a line item. $approver must be admin/manager and $passcode must match their
     * PIN — this is the passcode gate CLAUDE.md requires for removing an item from an
     * open ticket. $requestedBy is the cashier operating the terminal, recorded
     * separately from $approver so the audit trail shows who asked and who authorized.
     */
    public function VoidItem(TicketItem $ticketItem, User $requestedBy, User $approver, string $passcode): TicketItem
    {
        $this->assertPasscode($approver, $passcode);

        return DB::transaction(function () use ($ticketItem, $requestedBy, $approver): TicketItem {
            $lockedItem = TicketItem::query()->lockForUpdate()->findOrFail($ticketItem->id);

            if ($lockedItem->isVoided()) {
                throw new InvalidArgumentException('Item is already voided.');
            }

            $ticket = Ticket::query()->lockForUpdate()->findOrFail($lockedItem->ticket_id);

            if (! $ticket->isOpen()) {
                throw new InvalidArgumentException('Cannot void an item on a ticket that is not open.');
            }

            $this->inventoryService->ReleaseItem($lockedItem->item, $lockedItem->quantity);

            $lockedItem->update([
                'voided_at' => now(),
                'voided_by' => $approver->id,
                'voided_requested_by' => $requestedBy->id,
            ]);

            $this->recalculateTotals($ticket);

            return $lockedItem;
        });
    }

    public function SetDiscount(Ticket $ticket, float $discountAmount = 0, float $discountPercent = 0): Ticket
    {
        return DB::transaction(function () use ($ticket, $discountAmount, $discountPercent): Ticket {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $lockedTicket->isOpen()) {
                throw new InvalidArgumentException('Cannot change discount on a ticket that is not open.');
            }

            $lockedTicket->update([
                'discount_amount' => $discountAmount,
                'discount_percent' => $discountPercent,
            ]);

            $this->recalculateTotals($lockedTicket);

            return $lockedTicket->fresh();
        });
    }

    public function CancelTicket(Ticket $ticket, User $cancelledBy): Ticket
    {
        return DB::transaction(function () use ($ticket, $cancelledBy): Ticket {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $lockedTicket->isOpen()) {
                throw new InvalidArgumentException('Only open tickets can be cancelled.');
            }

            $items = TicketItem::query()
                ->where('ticket_id', $lockedTicket->id)
                ->whereNull('voided_at')
                ->with('item')
                ->get();

            foreach ($items as $ticketItem) {
                $this->inventoryService->ReleaseItem($ticketItem->item, $ticketItem->quantity);
            }

            $lockedTicket->update([
                'status' => 'cancelled',
                'cancelled_by' => $cancelledBy->id,
                'cancelled_at' => now(),
            ]);

            return $lockedTicket;
        });
    }

    private function nextOrderNumber(Shift $shift): string
    {
        $maxNumber = Ticket::query()
            ->where('shift_id', $shift->id)
            ->lockForUpdate()
            ->selectRaw('MAX(CAST(SUBSTRING(order_number, 2) AS UNSIGNED)) as max_number')
            ->value('max_number');

        $nextNumber = ((int) $maxNumber) + 1;

        return '#'.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function nextAvailableName(Shift $shift, string $baseName): string
    {
        $name = $baseName;
        $suffix = 1;

        while (Ticket::query()
            ->where('shift_id', $shift->id)
            ->where('status', 'open')
            ->where('customer_name', $name)
            ->exists()) {
            $suffix++;
            $name = $baseName.$suffix;
        }

        return $name;
    }

    /**
     * Recalculates subtotal/total from non-voided lines. When discount_percent is set
     * (> 0) it takes precedence over discount_amount; otherwise the fixed amount applies.
     */
    private function recalculateTotals(Ticket $ticket): void
    {
        $subtotal = (float) TicketItem::query()
            ->where('ticket_id', $ticket->id)
            ->whereNull('voided_at')
            ->sum('line_total');

        $discountPercent = (float) $ticket->discount_percent;
        $discountAmount = (float) $ticket->discount_amount;

        $effectiveDiscount = $discountPercent > 0
            ? round($subtotal * $discountPercent / 100, 2)
            : $discountAmount;

        $total = max(0, round($subtotal - $effectiveDiscount, 2));

        $ticket->update([
            'subtotal' => round($subtotal, 2),
            'total' => $total,
        ]);
    }

    private function assertPasscode(User $approver, string $passcode): void
    {
        if (! $approver->isAdminOrManager() || ! Hash::check($passcode, $approver->passcode)) {
            throw new InvalidPasscodeException;
        }
    }
}
