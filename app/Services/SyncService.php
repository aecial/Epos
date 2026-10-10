<?php

namespace App\Services;

use App\Exceptions\NoActiveShiftException;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\PosDevice;
use App\Models\Receipt;
use App\Models\Shift;
use App\Models\ShiftAlias;
use App\Models\ShiftTransaction;
use App\Models\SyncAction;
use App\Models\SyncIssue;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\Sync\SyncContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads a phone's notebook: the actions it saved (offline, or online a second ago), applied in
 * the order they happened through the same services the per-action endpoints use.
 *
 * - Every action is recorded once in sync_actions; a resent action id returns the stored result.
 * - Each action is applied on its own: a rejected one doesn't undo the ones before it.
 * - An offline action already happened in the real world, so it is accepted where an online one
 *   would be refused (stock below zero, the price the phone charged, a shift closed meanwhile),
 *   and each such disagreement becomes a SyncIssue for a manager.
 * - An unexpected failure (e.g. the database going away) isn't recorded: the request fails and
 *   the phone resends the batch; whatever was already applied replays as a no-op.
 */
class SyncService
{
    public const ACTION_TYPES = [
        'shift.open',
        'ticket.create',
        'ticket.add_item',
        'ticket.set_quantity',
        'ticket.void_item',
        'ticket.discount',
        'ticket.cancel',
        'ticket.charge',
        'shift_transaction.add',
        'shift_transaction.update',
        'shift_transaction.delete',
    ];

    /** How far ahead of the server a phone's clock may be before its time is distrusted. */
    private const CLOCK_TOLERANCE_MINUTES = 5;

    /** @var array<int, array{type: string, message: string, details: array<string, mixed>, shift_id: int|null, ticket_id: int|null}> */
    private array $issues = [];

    public function __construct(
        private TicketService $ticketService,
        private PaymentService $paymentService,
        private ShiftService $shiftService,
        private ShiftTransactionService $shiftTransactionService,
        private InventoryService $inventoryService,
    ) {}

    /**
     * @param  array<int, array{id: string, type: string, offline: bool, happened_at?: string|null, data?: array<string, mixed>}>  $actions
     * @return array<int, array<string, mixed>>
     */
    public function Sync(User $user, PosDevice $device, array $actions, int $pendingActions): array
    {
        $results = array_map(fn (array $action): array => $this->apply($user, $device, $action), $actions);

        $device->update([
            'pending_actions' => $pendingActions,
            'last_seen_at' => now(),
            'last_synced_at' => now(),
        ]);

        return $results;
    }

    /**
     * @param  array{id: string, type: string, offline: bool, happened_at?: string|null, data?: array<string, mixed>}  $action
     * @return array<string, mixed>
     */
    private function apply(User $user, PosDevice $device, array $action): array
    {
        $existing = SyncAction::query()->where('uuid', $action['id'])->first();

        if ($existing !== null) {
            return $this->present($existing);
        }

        $offline = (bool) $action['offline'];
        $this->issues = [];

        try {
            $recorded = DB::transaction(function () use ($user, $device, $action, $offline): SyncAction {
                $at = $this->happenedAt($action, $offline);
                $result = $this->handle($action['type'], $action['data'] ?? [], $user, $device, $offline, $at);

                return $this->record($user, $device, $action, $at, $this->issues === [] ? 'applied' : 'applied_with_issue', $result);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same action arriving twice at once: the other request recorded it first.
            // Otherwise a phone id (ticket, line, payment...) was already used by another action.
            $recorded = SyncAction::query()->where('uuid', $action['id'])->first()
                ?? $this->reject($user, $device, $action, $offline, new InvalidArgumentException('Something with this id was already received from another action.'));
        } catch (QueryException $e) {
            // A database failure isn't the action's fault: fail the request so the phone retries.
            throw $e;
        } catch (ValidationException|InvalidArgumentException|RuntimeException|ModelNotFoundException|AuthorizationException $e) {
            $recorded = $this->reject($user, $device, $action, $offline, $e);
        }

        return $this->present($recorded);
    }

    /**
     * Records an action that couldn't be applied. Offline it's also a sync issue: it happened in
     * the restaurant, so a manager has to sort it out by hand.
     *
     * @param  array<string, mixed>  $action
     */
    private function reject(User $user, PosDevice $device, array $action, bool $offline, \Throwable $e): SyncAction
    {
        $this->issues = [];
        $message = $this->rejectionMessage($e);

        if ($offline) {
            $this->raise('rejected_action', "An offline {$action['type']} couldn't be applied: {$message}", [
                'action' => $action['type'],
                'data' => $action['data'] ?? [],
            ]);
        }

        return DB::transaction(fn (): SyncAction => $this->record($user, $device, $action, $this->happenedAtOrNull($action), 'rejected', null, $message));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function handle(string $type, array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        return match ($type) {
            'shift.open' => $this->openShift($data, $user, $device, $offline, $at),
            'ticket.create' => $this->createTicket($data, $user, $device, $offline, $at),
            'ticket.add_item' => $this->addItem($data, $user, $device, $offline, $at),
            'ticket.set_quantity' => $this->setQuantity($data, $user, $device, $offline, $at),
            'ticket.void_item' => $this->voidItem($data, $user, $device, $offline, $at),
            'ticket.discount' => $this->setDiscount($data, $user),
            'ticket.cancel' => $this->cancelTicket($data, $user, $device, $offline, $at),
            'ticket.charge' => $this->chargeTicket($data, $user, $device, $offline, $at),
            'shift_transaction.add' => $this->addTransaction($data, $user, $device, $offline, $at),
            'shift_transaction.update' => $this->updateTransaction($data, $user),
            'shift_transaction.delete' => $this->deleteTransaction($data, $user),
            default => throw new InvalidArgumentException("Unknown action type {$type}."),
        };
    }

    /**
     * Online: opens the shift like POST /shifts. Offline: the phone started a shift with no
     * server to ask, so it joins whichever shift covers that moment (or the one open now), and
     * only becomes a shift of its own when there is none.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function openShift(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        $data = $this->validate($data, [
            'shift_uuid' => ['required', 'uuid'],
            'starting_cash' => ['required', 'numeric', 'min:0'],
        ]);

        if (! $offline) {
            $shift = $this->shiftService->OpenShift($user, (float) $data['starting_cash'], $data['shift_uuid']);

            return ['shift_id' => $shift->id, 'joined' => false];
        }

        $target = Shift::query()
            ->where('opened_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $at))
            ->latest('opened_at')
            ->first()
            ?? Shift::query()->where('status', 'open')->first();

        if ($target === null) {
            $shift = $this->shiftService->OpenShift($user, (float) $data['starting_cash'], $data['shift_uuid'], new SyncContext($device, true, $at));

            return ['shift_id' => $shift->id, 'joined' => false];
        }

        ShiftAlias::create(['client_uuid' => $data['shift_uuid'], 'shift_id' => $target->id, 'pos_device_id' => $device->id]);

        if ((int) round((float) $target->starting_cash * 100) !== (int) round((float) $data['starting_cash'] * 100)) {
            $this->raise('starting_cash_conflict', "{$device->code} started a shift offline with ₱".number_format((float) $data['starting_cash'], 2).' in the drawer; the shift it joined started with ₱'.number_format((float) $target->starting_cash, 2).'.', [
                'phone_starting_cash' => (float) $data['starting_cash'],
                'shift_starting_cash' => (float) $target->starting_cash,
            ], shift: $target);
        }

        if ($target->opened_at->toDateString() < $at->toDateString()) {
            $this->raise('old_shift_joined', "{$device->code}'s offline shift was joined into a shift opened on {$target->opened_at->toDateString()} that was never closed.", [
                'shift_opened_at' => $target->opened_at->toIso8601String(),
            ], shift: $target);
        }

        $this->flagIfClosed($target, $device);

        return ['shift_id' => $target->id, 'joined' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createTicket(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        $data = $this->validate($data, [
            'ticket_uuid' => ['required', 'uuid'],
            'shift_uuid' => ['sometimes', 'nullable', 'uuid'],
            'shift_id' => ['sometimes', 'nullable', 'integer'],
            'terminal_id' => ['required', 'string', 'max:50'],
            'customer_name' => ['required', 'string', 'max:255'],
            'order_type' => ['required', 'in:dine_in,takeout'],
            'offline_label' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $shift = $this->resolveShift($data) ?? $this->shiftService->ActiveShift() ?? throw new NoActiveShiftException;
        $at = $this->clampToShift($at, $shift, $offline);

        if ($offline) {
            $this->flagIfClosed($shift, $device);
        }

        $ticket = $this->ticketService->CreateTicket(
            $shift,
            $user,
            $data['terminal_id'],
            $data['customer_name'],
            $data['order_type'],
            $data['ticket_uuid'],
            new SyncContext($device, $offline, $at),
            $data['offline_label'] ?? null,
        );

        return [
            'ticket_id' => $ticket->id,
            'shift_id' => $ticket->shift_id,
            'order_number' => $ticket->order_number,
            'customer_name' => $ticket->customer_name,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function addItem(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        $data = $this->validate($data, [
            ...$this->ticketReferenceRules(),
            'line_uuid' => ['required', 'uuid'],
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'modifier_ids' => ['sometimes', 'array'],
            'modifier_ids.*' => ['integer', 'distinct'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'custom_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:100', 'regex:/^[^\r\n]+$/'],
        ]);

        $ticket = $this->resolveTicket($data, $user);
        $item = Item::query()->findOrFail($data['item_id']);
        $modifierIds = array_map('intval', $data['modifier_ids'] ?? []);
        $quantity = (int) $data['quantity'];
        $unitPrice = isset($data['unit_price']) ? (float) $data['unit_price'] : null;

        if ($offline) {
            $isStockless = $modifierIds !== [] && Modifier::query()->whereKey($modifierIds)->where('is_stockless_variant', true)->exists();

            if (! $isStockless) {
                $this->flagShortStock($item, $quantity, $ticket, $device);
            }

            foreach ($this->ticketService->MenuRuleViolations($item, $modifierIds) as $type => $message) {
                $this->raise($type, "Sold offline on {$device->code}: {$message}", ['item' => $item->name], ticket: $ticket);
            }

            if ($unitPrice !== null && ($item->entry_mode ?? 'fixed') === 'fixed' && $this->cents($unitPrice) !== $this->cents($item->base_price)) {
                $this->raise('price_changed', "{$item->name} was sold offline at ₱".number_format($unitPrice, 2).'; the menu says ₱'.number_format((float) $item->base_price, 2).'.', [
                    'item' => $item->name,
                    'charged_price' => $unitPrice,
                    'menu_price' => (float) $item->base_price,
                ], ticket: $ticket);
            }
        }

        $line = $this->ticketService->AddItem(
            $ticket,
            $item,
            $quantity,
            $modifierIds,
            $data['notes'] ?? null,
            $unitPrice,
            $data['custom_name'] ?? null,
            $data['line_uuid'],
            new SyncContext($device, $offline, $at),
        );

        return [
            'ticket_id' => $ticket->id,
            'ticket_item_id' => $line->id,
            'line_total' => (float) $line->line_total,
            'ticket_total' => (float) $ticket->fresh()->total,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function setQuantity(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        $data = $this->validate($data, [
            ...$this->lineReferenceRules(),
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $line = $this->resolveLine($data, $user);
        $increase = (int) $data['quantity'] - (int) $line->quantity;

        if ($offline && $increase > 0 && ! $line->is_stockless && $line->item !== null) {
            $this->flagShortStock($line->item, $increase, $line->ticket, $device);
        }

        $updated = $this->ticketService->UpdateItemQuantity($line, (int) $data['quantity'], new SyncContext($device, $offline, $at));

        return [
            'ticket_id' => $updated->ticket_id,
            'ticket_item_id' => $updated->id,
            'line_total' => (float) $updated->line_total,
            'ticket_total' => (float) $updated->ticket->fresh()->total,
        ];
    }

    /**
     * Only offline: online, removing an item goes through DELETE /tickets/{id}/items/{line} with
     * a manager's passcode. Offline no passcode can be checked, so a reason is kept and a
     * manager reviews it afterwards.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function voidItem(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        if (! $offline) {
            throw new InvalidArgumentException('Removing an item online needs a manager passcode: use DELETE /tickets/{ticket}/items/{ticketItem}.');
        }

        $data = $this->validate($data, [
            ...$this->lineReferenceRules(),
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $line = $this->resolveLine($data, $user);
        $voided = $this->ticketService->VoidItemOffline($line, $user, $data['reason'], new SyncContext($device, true, $at));

        $this->raise('offline_void', "{$user->name} removed {$voided->quantity}x {$voided->item_name} offline without a passcode: \"{$data['reason']}\".", [
            'item' => $voided->item_name,
            'quantity' => (int) $voided->quantity,
            'line_total' => (float) $voided->line_total,
            'reason' => $data['reason'],
        ], ticket: $voided->ticket);

        return [
            'ticket_id' => $voided->ticket_id,
            'ticket_item_id' => $voided->id,
            'ticket_total' => (float) $voided->ticket->fresh()->total,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function setDiscount(array $data, User $user): array
    {
        $data = $this->validate($data, [
            ...$this->ticketReferenceRules(),
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $ticket = $this->ticketService->SetDiscount(
            $this->resolveTicket($data, $user),
            (float) ($data['discount_amount'] ?? 0),
            (float) ($data['discount_percent'] ?? 0),
        );

        return ['ticket_id' => $ticket->id, 'ticket_total' => (float) $ticket->total];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cancelTicket(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        $data = $this->validate($data, $this->ticketReferenceRules());

        $ticket = $this->ticketService->CancelTicket($this->resolveTicket($data, $user), $user, new SyncContext($device, $offline, $at));

        return ['ticket_id' => $ticket->id, 'status' => $ticket->status];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function chargeTicket(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        $data = $this->validate($data, [
            ...$this->ticketReferenceRules(),
            'charges' => ['required', 'array', 'min:1'],
            'charges.*.charge_uuid' => ['required', 'uuid', 'distinct'],
            'charges.*.payment_method' => ['required', 'in:cash,gcash'],
            'charges.*.amount' => ['required', 'numeric', 'gte:0'],
            'charges.*.tendered_amount' => ['sometimes', 'nullable', 'numeric', 'gte:charges.*.amount', 'prohibited_if:charges.*.payment_method,gcash'],
            'charges.*.payment_reference' => ['required_if:charges.*.payment_method,gcash', 'nullable', 'string', 'max:255'],
            'charges.*.receipt_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $ticket = $this->resolveTicket($data, $user);
        $paid = round((float) array_sum(array_column($data['charges'], 'amount')), 2);

        if ($offline && ! $ticket->isOpen()) {
            // The customer paid on the phone, but the server already has this ticket settled
            // (paid, cancelled or merged elsewhere). Nothing is charged twice: a manager decides.
            $this->raise('possible_double_payment', "{$ticket->order_number} {$ticket->customer_name} was paid ₱".number_format($paid, 2)." offline, but the ticket was already {$ticket->status}.", [
                'ticket_status' => $ticket->status,
                'charges' => $data['charges'],
            ], ticket: $ticket);

            return ['ticket_id' => $ticket->id, 'status' => $ticket->status, 'charged' => false];
        }

        if ($offline) {
            if ($this->cents($paid) !== $this->cents($ticket->total)) {
                $this->raise('charge_mismatch', "{$ticket->order_number} {$ticket->customer_name} was paid ₱".number_format($paid, 2).' offline; the server worked out ₱'.number_format((float) $ticket->total, 2).'. The difference was booked as a discount.', [
                    'paid' => $paid,
                    'server_total' => (float) $ticket->total,
                ], ticket: $ticket);
            }

            foreach ($data['charges'] as $charge) {
                if (filled($charge['receipt_number'] ?? null) && Receipt::query()->where('receipt_number', $charge['receipt_number'])->exists()) {
                    $this->raise('receipt_number_taken', "Receipt number {$charge['receipt_number']} printed offline was already used; the server gave this payment a new number.", [
                        'printed_number' => $charge['receipt_number'],
                    ], ticket: $ticket);
                }
            }
        }

        $paidTicket = $this->paymentService->ChargeTicket(
            $ticket,
            $user,
            array_map(fn (array $charge): array => [
                'client_uuid' => $charge['charge_uuid'],
                'payment_method' => $charge['payment_method'],
                'amount' => (float) $charge['amount'],
                'tendered_amount' => isset($charge['tendered_amount']) ? (float) $charge['tendered_amount'] : null,
                'payment_reference' => $charge['payment_reference'] ?? null,
                'receipt_number' => $charge['receipt_number'] ?? null,
            ], $data['charges']),
            new SyncContext($device, $offline, $at),
        );

        return [
            'ticket_id' => $paidTicket->id,
            'status' => $paidTicket->status,
            'charged' => true,
            // The printable receipt comes back with the payment, so the POS prints straight away
            // (and a replayed action returns the same receipt).
            'receipts' => $paidTicket->charges->map(fn ($charge): array => [
                'charge_uuid' => $charge->client_uuid,
                'charge_id' => $charge->id,
                'receipt_id' => $charge->receipt?->id,
                'receipt_number' => $charge->receipt?->receipt_number,
                'payload' => $charge->receipt?->payload,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function addTransaction(array $data, User $user, PosDevice $device, bool $offline, CarbonImmutable $at): array
    {
        $data = $this->validate($data, [
            'transaction_uuid' => ['required', 'uuid'],
            'shift_uuid' => ['sometimes', 'nullable', 'uuid'],
            'shift_id' => ['sometimes', 'nullable', 'integer'],
            'type' => ['required', 'in:expense,addition'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $shift = $this->resolveShift($data) ?? $this->shiftService->ActiveShift() ?? throw new NoActiveShiftException;
        $at = $this->clampToShift($at, $shift, $offline);

        if ($offline) {
            $this->flagIfClosed($shift, $device);
        }

        $transaction = $this->shiftTransactionService->AddTransaction(
            $shift,
            $user,
            $data['type'],
            (float) $data['amount'],
            $data['reason'],
            $data['transaction_uuid'],
            new SyncContext($device, $offline, $at),
        );

        return ['transaction_id' => $transaction->id, 'shift_id' => $shift->id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function updateTransaction(array $data, User $user): array
    {
        $data = $this->validate($data, [
            ...$this->transactionReferenceRules(),
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $transaction = $this->shiftTransactionService->UpdateTransaction($this->resolveTransaction($data), $user, (float) $data['amount'], $data['reason']);

        return ['transaction_id' => $transaction->id];
    }

    /**
     * Like DELETE /shifts/{shift}/transactions/{transaction}: any staff may remove a mistaken entry.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deleteTransaction(array $data, User $user): array
    {
        $data = $this->validate($data, $this->transactionReferenceRules());
        $transaction = $this->resolveTransaction($data);

        $this->shiftTransactionService->DeleteTransaction($transaction, $user);

        return ['transaction_id' => $transaction->id];
    }

    /**
     * The phone's time for an offline action (its clock corrected by the server time it last
     * saw); the server's own time for an online one. A time in the future means the phone's clock
     * is wrong, so the sync time is used instead.
     *
     * @param  array<string, mixed>  $action
     */
    private function happenedAt(array $action, bool $offline): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if (! $offline) {
            return $now;
        }

        $at = CarbonImmutable::parse($action['happened_at'])->setTimezone(config('app.timezone'));

        if ($at->greaterThan($now->addMinutes(self::CLOCK_TOLERANCE_MINUTES))) {
            $this->raise('clock_wrong', "An offline {$action['type']} was dated {$at->toDateTimeString()}, in the future; it was recorded at the sync time instead.", [
                'phone_time' => $at->toIso8601String(),
            ]);

            return $now;
        }

        return $at;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function happenedAtOrNull(array $action): ?CarbonImmutable
    {
        return filled($action['happened_at'] ?? null) ? CarbonImmutable::parse($action['happened_at'])->setTimezone(config('app.timezone')) : null;
    }

    /**
     * Nothing in a shift can happen before it opened: an earlier time means the phone's clock was
     * wrong, so it is moved to the shift's opening.
     */
    private function clampToShift(CarbonImmutable $at, Shift $shift, bool $offline): CarbonImmutable
    {
        if (! $offline || ! $at->lessThan($shift->opened_at)) {
            return $at;
        }

        $this->raise('clock_wrong', "An offline action was dated {$at->toDateTimeString()}, before its shift opened; it was recorded at the shift's opening instead.", [
            'phone_time' => $at->toIso8601String(),
            'shift_opened_at' => $shift->opened_at->toIso8601String(),
        ], shift: $shift);

        return CarbonImmutable::parse($shift->opened_at);
    }

    private function flagIfClosed(Shift $shift, PosDevice $device): void
    {
        if ($shift->isOpen()) {
            return;
        }

        $this->raise('synced_after_close', "{$device->code} sent offline activity for a shift that was already closed; its closing totals don't include it.", [
            'shift_closed_at' => $shift->closed_at?->toIso8601String(),
        ], shift: $shift);
    }

    private function flagShortStock(Item $item, int $quantity, Ticket $ticket, PosDevice $device): void
    {
        if ($item->inventory_type === 'none') {
            return;
        }

        try {
            $available = $this->inventoryService->AvailableForItem($item);
        } catch (InvalidArgumentException) {
            return;
        }

        if ($available >= $quantity) {
            return;
        }

        $this->raise('stock_short', "{$device->code} sold {$quantity}x {$item->name} offline with only ".max(0, $available).' available; its stock went below zero.', [
            'item' => $item->name,
            'sold' => $quantity,
            'available' => $available,
        ], ticket: $ticket);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function raise(string $type, string $message, array $details = [], ?Shift $shift = null, ?Ticket $ticket = null): void
    {
        $this->issues[] = [
            'type' => $type,
            'message' => $message,
            'details' => $details,
            'shift_id' => $shift?->id ?? $ticket?->shift_id,
            'ticket_id' => $ticket?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>|null  $result
     */
    private function record(User $user, PosDevice $device, array $action, ?CarbonImmutable $at, string $status, ?array $result, ?string $message = null): SyncAction
    {
        $syncAction = SyncAction::create([
            'uuid' => $action['id'],
            'pos_device_id' => $device->id,
            'user_id' => $user->id,
            'type' => $action['type'],
            'offline' => (bool) $action['offline'],
            'happened_at' => $at,
            'status' => $status,
            'result' => $result,
            'message' => $message,
        ]);

        foreach ($this->issues as $issue) {
            SyncIssue::create([
                ...$issue,
                'sync_action_id' => $syncAction->id,
                'pos_device_id' => $device->id,
                'user_id' => $user->id,
            ]);
        }

        return $syncAction;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SyncAction $syncAction): array
    {
        return [
            'id' => $syncAction->uuid,
            'type' => $syncAction->type,
            'status' => $syncAction->status,
            'message' => $syncAction->message,
            'result' => $syncAction->result,
            'issues' => SyncIssue::query()
                ->where('sync_action_id', $syncAction->id)
                ->get(['type', 'message'])
                ->map(fn (SyncIssue $issue): array => ['type' => $issue->type, 'message' => $issue->message])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function ticketReferenceRules(): array
    {
        return [
            'ticket_uuid' => ['required_without:ticket_id', 'nullable', 'uuid'],
            'ticket_id' => ['required_without:ticket_uuid', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function lineReferenceRules(): array
    {
        return [
            'line_uuid' => ['required_without:ticket_item_id', 'nullable', 'uuid'],
            'ticket_item_id' => ['required_without:line_uuid', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function transactionReferenceRules(): array
    {
        return [
            'transaction_uuid' => ['required_without:transaction_id', 'nullable', 'uuid'],
            'transaction_id' => ['required_without:transaction_uuid', 'nullable', 'integer'],
        ];
    }

    /**
     * A shift by the phone's id (its own, or one joined into another) or by the server id;
     * null when the action names none.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveShift(array $data): ?Shift
    {
        if (filled($data['shift_uuid'] ?? null)) {
            return Shift::query()->where('client_uuid', $data['shift_uuid'])->first()
                ?? ShiftAlias::query()->where('client_uuid', $data['shift_uuid'])->first()?->shift
                ?? throw new ModelNotFoundException('That shift never reached the server.');
        }

        return filled($data['shift_id'] ?? null) ? Shift::query()->findOrFail($data['shift_id']) : null;
    }

    /**
     * A ticket by the phone's id or the server id, which the user must be allowed to act on (a
     * cashier only on tickets they opened, same as every per-ticket route).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTicket(array $data, User $user): Ticket
    {
        $ticket = filled($data['ticket_uuid'] ?? null)
            ? Ticket::query()->where('client_uuid', $data['ticket_uuid'])->first() ?? throw new ModelNotFoundException('That ticket never reached the server.')
            : Ticket::query()->findOrFail($data['ticket_id']);

        Gate::forUser($user)->authorize('manage', $ticket);

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveLine(array $data, User $user): TicketItem
    {
        $line = filled($data['line_uuid'] ?? null)
            ? TicketItem::query()->where('client_uuid', $data['line_uuid'])->first() ?? throw new ModelNotFoundException('That item never reached the server.')
            : TicketItem::query()->findOrFail($data['ticket_item_id']);

        Gate::forUser($user)->authorize('manage', $line->ticket);

        return $line;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTransaction(array $data): ShiftTransaction
    {
        return filled($data['transaction_uuid'] ?? null)
            ? ShiftTransaction::query()->where('client_uuid', $data['transaction_uuid'])->first() ?? throw new ModelNotFoundException('That cash entry never reached the server.')
            : ShiftTransaction::query()->findOrFail($data['transaction_id']);
    }

    private function rejectionMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => collect($e->errors())->flatten()->first() ?? 'The action was invalid.',
            $e instanceof ModelNotFoundException => $e->getMessage() !== '' && ! str_starts_with($e->getMessage(), 'No query results') ? $e->getMessage() : 'Not found.',
            $e instanceof AuthorizationException => $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.' && $e->getMessage() !== 'Resource not found.' ? $e->getMessage() : 'Not found.',
            default => $e->getMessage(),
        };
    }

    private function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
