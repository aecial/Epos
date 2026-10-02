<?php

namespace App\Services;

use App\Events\Kds\ItemCompleted;
use App\Events\Kds\ItemUncompleted;
use App\Events\Kds\TicketCancelled;
use App\Events\Kds\TicketCreated;
use App\Events\Kds\TicketMerged;
use App\Events\Kds\TicketUpdated;
use App\Exceptions\NoActiveShiftException;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\TicketItemModifier;
use App\Models\User;
use App\Services\Concerns\BroadcastsSafely;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TicketService
{
    use BroadcastsSafely;

    public function __construct(
        private InventoryService $inventoryService,
        private PasscodeService $passcodeService,
    ) {}

    public function CreateTicket(Shift $shift, User $createdBy, string $terminalId, string $customerName, string $orderType): Ticket
    {
        $ticket = DB::transaction(function () use ($shift, $createdBy, $terminalId, $customerName, $orderType): Ticket {
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

        $this->broadcastSafely(fn () => broadcast(new TicketCreated($ticket)));

        return $ticket;
    }

    /**
     * $unitPrice and $customName are only for Special items: a Fee item (entry_mode 'price')
     * takes an amount, a Custom item ('name_price') takes a name and an amount. Any other item
     * always sells at its own base_price; the request layer rejects overrides, and this method
     * refuses them too so a direct service call can't bypass that.
     *
     * @param  array<int, int>  $modifierIds
     */
    public function AddItem(Ticket $ticket, Item $item, int $quantity, array $modifierIds = [], ?string $notes = null, ?float $unitPrice = null, ?string $customName = null): TicketItem
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        $entryMode = $item->entry_mode ?? 'fixed';
        $needsPrice = in_array($entryMode, ['price', 'name_price'], true);
        $needsName = $entryMode === 'name_price';

        if ($needsPrice !== ($unitPrice !== null) || $needsName !== ($customName !== null)) {
            throw new InvalidArgumentException("Item {$item->name} does not accept this price/name combination.");
        }

        if ($unitPrice !== null && $unitPrice <= 0) {
            throw new InvalidArgumentException('Price must be greater than zero.');
        }

        $ticketItem = DB::transaction(function () use ($ticket, $item, $quantity, $modifierIds, $notes, $unitPrice, $customName, $entryMode): TicketItem {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $lockedTicket->isOpen()) {
                throw new InvalidArgumentException('Cannot add items to a ticket that is not open.');
            }

            $itemModifiers = $modifierIds === []
                ? collect()
                : $item->sellableModifiers()->whereKey($modifierIds)->get();

            if ($itemModifiers->count() !== count(array_unique($modifierIds))) {
                throw new InvalidArgumentException("One or more modifiers are not available for {$item->name}.");
            }

            $this->inventoryService->ReserveItem($item, $quantity);

            $unitPrice ??= (float) $item->base_price;
            $modifierTotal = (float) $itemModifiers->sum(fn (Modifier $modifier): float => (float) $modifier->pivot->price_modifier);
            $lineTotal = round(($unitPrice + $modifierTotal) * $quantity, 2);

            $ticketItem = TicketItem::create([
                'ticket_id' => $lockedTicket->id,
                'item_id' => $item->id,
                // Custom items are named by the cashier; the typed name is the snapshot that
                // reaches receipts, refunds and the KDS.
                'item_name' => $customName ?? $item->name,
                'item_cost_price' => $item->cost_price,
                'line_type' => $this->lineTypeFor($item, $entryMode),
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

        $this->broadcastSafely(fn () => broadcast(new TicketUpdated($ticketItem->ticket)));

        return $ticketItem;
    }

    /**
     * Void a line item. $passcode must belong to exactly one active admin/manager, who is
     * recorded as the approver — this is the passcode gate CLAUDE.md requires for removing
     * an item from an open ticket. $requestedBy is the cashier operating the terminal,
     * recorded separately so the audit trail shows who asked and who authorized.
     */
    public function VoidItem(TicketItem $ticketItem, User $requestedBy, string $passcode): TicketItem
    {
        $approver = $this->passcodeService->ResolveApprover($requestedBy, $passcode);

        $voided = DB::transaction(function () use ($ticketItem, $requestedBy, $approver): TicketItem {
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

        $this->broadcastSafely(fn () => broadcast(new TicketUpdated($voided->ticket)));

        return $voided;
    }

    /**
     * Change a line's quantity up or down. No passcode: this is not a removal, so it does
     * not go through PasscodeService. Reaching zero is a void instead — the request layer
     * enforces quantity >= 1, and VoidItem (passcode-gated) is how a line is removed entirely.
     */
    public function UpdateItemQuantity(TicketItem $ticketItem, int $newQuantity): TicketItem
    {
        if ($newQuantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero; void the line to remove it.');
        }

        $updated = DB::transaction(function () use ($ticketItem, $newQuantity): TicketItem {
            $lockedItem = TicketItem::query()->lockForUpdate()->findOrFail($ticketItem->id);

            if ($lockedItem->isVoided()) {
                throw new InvalidArgumentException('Cannot change the quantity of a voided item.');
            }

            $ticket = Ticket::query()->lockForUpdate()->findOrFail($lockedItem->ticket_id);

            if (! $ticket->isOpen()) {
                throw new InvalidArgumentException('Cannot change item quantity on a ticket that is not open.');
            }

            $delta = $newQuantity - $lockedItem->quantity;

            if ($delta > 0) {
                $this->inventoryService->ReserveItem($lockedItem->item, $delta);
            } elseif ($delta < 0) {
                $this->inventoryService->ReleaseItem($lockedItem->item, abs($delta));
            }

            $modifierTotal = (float) $lockedItem->modifiers()->sum('price');
            $lineTotal = round(((float) $lockedItem->unit_price + $modifierTotal) * $newQuantity, 2);

            $lockedItem->update([
                'quantity' => $newQuantity,
                'line_total' => $lineTotal,
            ]);

            $this->recalculateTotals($ticket);

            return $lockedItem->fresh(['modifiers']);
        });

        $this->broadcastSafely(fn () => broadcast(new TicketUpdated($updated->ticket)));

        return $updated;
    }

    /**
     * KDS completion toggle. No passcode: this isn't a removal, same reasoning as
     * UpdateItemQuantity. Not persisted as audit/historical data - a nightly job
     * (kds:clear-completed) sweeps completed_at back to null regardless of ticket status.
     */
    public function SetItemCompletion(TicketItem $ticketItem, bool $completed): TicketItem
    {
        $updated = DB::transaction(function () use ($ticketItem, $completed): TicketItem {
            $lockedItem = TicketItem::query()->lockForUpdate()->findOrFail($ticketItem->id);

            if ($lockedItem->isVoided()) {
                throw new InvalidArgumentException('Cannot change the completion state of a voided item.');
            }

            $ticket = Ticket::query()->lockForUpdate()->findOrFail($lockedItem->ticket_id);

            if (! $ticket->isOpen()) {
                throw new InvalidArgumentException('Cannot change item completion state on a ticket that is not open.');
            }

            $lockedItem->update(['completed_at' => $completed ? now() : null]);

            return $lockedItem;
        });

        $this->broadcastSafely(fn () => broadcast($completed ? new ItemCompleted($updated) : new ItemUncompleted($updated)));

        return $updated;
    }

    /**
     * Bumps every pending (non-voided, kitchen-visible) line on the ticket in one action - the
     * KDS "tap the customer name" gesture, instead of tapping each item individually. Broadcasts
     * ticket.updated: the resulting card has nothing left pending, so the feed drops it until a
     * new item is added.
     */
    public function CompleteTicketItems(Ticket $ticket): Ticket
    {
        $completed = DB::transaction(function () use ($ticket): Ticket {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $lockedTicket->isOpen()) {
                throw new InvalidArgumentException('Cannot complete items on a ticket that is not open.');
            }

            TicketItem::query()
                ->where('ticket_id', $lockedTicket->id)
                ->whereNull('voided_at')
                ->whereNull('completed_at')
                ->whereIn('line_type', ['item', 'custom'])
                ->update(['completed_at' => now()]);

            return $lockedTicket;
        });

        $this->broadcastSafely(fn () => broadcast(new TicketUpdated($completed)));

        return $completed;
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
        $cancelled = DB::transaction(function () use ($ticket, $cancelledBy): Ticket {
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

        $this->broadcastSafely(fn () => broadcast(new TicketCancelled($cancelled)));

        return $cancelled;
    }

    /**
     * Merge one or more open tickets into $target. Lines are physically moved onto the
     * target so it becomes the single bill; the sources stay as history with status
     * 'merged'. Reservations are per item, not per ticket, so inventory is untouched.
     *
     * @param  array<int, int>  $sourceIds
     */
    public function MergeTickets(Ticket $target, array $sourceIds, User $mergedBy): Ticket
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));

        if ($sourceIds === []) {
            throw new InvalidArgumentException('At least one ticket to merge is required.');
        }

        if (in_array((int) $target->id, $sourceIds, true)) {
            throw new InvalidArgumentException('A ticket cannot be merged into itself.');
        }

        [$merged, $removedTicketIds, $removedOrderNumbers] = DB::transaction(function () use ($target, $sourceIds, $mergedBy): array {
            // Lock every ticket involved in ascending id order. A fixed order means two
            // merges that touch overlapping tickets queue up instead of deadlocking.
            $ids = array_merge($sourceIds, [(int) $target->id]);
            sort($ids);

            $tickets = Ticket::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($tickets->count() !== count($ids)) {
                throw new InvalidArgumentException('One or more tickets to merge were not found.');
            }

            // Re-check under the lock: the state we validated before may be stale.
            foreach ($tickets as $ticket) {
                if (! $ticket->isOpen()) {
                    throw new InvalidArgumentException("Ticket {$ticket->order_number} is not open and cannot be merged.");
                }

                if ((int) $ticket->shift_id !== (int) $target->shift_id) {
                    throw new InvalidArgumentException('Tickets must belong to the same shift to be merged.');
                }

                if (! $mergedBy->isAdminOrManager() && (int) $ticket->created_by !== (int) $mergedBy->id) {
                    throw new InvalidArgumentException('You can only merge tickets you opened.');
                }
            }

            $lockedTarget = $tickets[(int) $target->id];
            $sources = $tickets->except((int) $target->id);
            // Eloquent Collection::except() re-indexes its result with array_values(), so the
            // source tickets' original id-keyed position is gone here - modelKeys() reads the
            // primary key directly instead of trusting the (now positional) collection keys.
            $removedTicketIds = $sources->modelKeys();
            $removedOrderNumbers = $sources->pluck('order_number')->all();

            // Carry every ticket's discount over as one fixed amount. Each ticket's
            // effective discount is simply subtotal - total (it already reflects the
            // percent-vs-amount rule), so no rule needs re-implementing here.
            $combinedDiscount = round((float) $tickets->sum(
                fn (Ticket $ticket): float => (float) $ticket->subtotal - (float) $ticket->total
            ), 2);

            $notes = collect([$lockedTarget->notes])
                ->merge($sources->map(fn (Ticket $source): ?string => filled($source->notes)
                    ? "{$source->order_number}: {$source->notes}"
                    : null))
                ->filter(fn (?string $note): bool => filled($note))
                ->implode("\n");

            foreach ($sources as $source) {
                // Remember where each line came from, but never overwrite an earlier origin
                // (A merged into B, then B into C: A's lines should still say "from A").
                TicketItem::query()
                    ->where('ticket_id', $source->id)
                    ->whereNull('merged_from_ticket_id')
                    ->update(['merged_from_ticket_id' => $source->id]);

                TicketItem::query()->where('ticket_id', $source->id)->update(['ticket_id' => $lockedTarget->id]);

                // Flatten chains: tickets already merged into this source now point at the
                // target, so merged_tickets on the target is always the complete list.
                Ticket::query()
                    ->where('merged_into_ticket_id', $source->id)
                    ->update(['merged_into_ticket_id' => $lockedTarget->id]);

                $source->update([
                    'status' => 'merged',
                    'merged_into_ticket_id' => $lockedTarget->id,
                    'merged_by' => $mergedBy->id,
                    'merged_at' => now(),
                    // Zeroed so the money now lives only on the target.
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'discount_percent' => 0,
                    'total' => 0,
                ]);
            }

            $lockedTarget->update([
                'discount_amount' => $combinedDiscount,
                'discount_percent' => 0,
                'notes' => $notes === '' ? null : $notes,
            ]);

            $this->recalculateTotals($lockedTarget);

            return [$lockedTarget->fresh(['items.modifiers', 'mergedTickets']), $removedTicketIds, $removedOrderNumbers];
        });

        $this->broadcastSafely(fn () => broadcast(new TicketMerged($merged, $removedTicketIds, $removedOrderNumbers)));
        $this->broadcastSafely(fn () => broadcast(new TicketUpdated($merged)));

        return $merged;
    }

    /**
     * A Custom item is a name+price line; any other item in a special category is a Fee;
     * everything else is a regular menu line.
     */
    private function lineTypeFor(Item $item, string $entryMode): string
    {
        if ($entryMode === 'name_price') {
            return 'custom';
        }

        return $item->category->isSpecial() ? 'fee' : 'item';
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
}
