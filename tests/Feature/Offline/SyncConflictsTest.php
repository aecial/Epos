<?php

use App\Models\Charge;
use App\Models\Receipt;
use App\Models\Shift;
use App\Models\ShiftAlias;
use App\Models\SyncIssue;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| What happens when the notebook disagrees with the server. An offline sale already happened, so
| it is accepted and a manager gets a sync issue; an online action through /sync keeps every
| rule the per-action endpoints have.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 18:00:00', 'Asia/Manila'));
});

/** A shift opened at 9:00 this morning, before the outage. */
function syncMorningShift(User $by): Shift
{
    $shift = posOpenShift($by);
    $shift->update(['opened_at' => Carbon::parse('2026-10-10 09:00', 'Asia/Manila')]);

    return $shift;
}

/**
 * A ticket opened offline at $at on the phone, with one line.
 *
 * @return array<int, array<string, mixed>>
 */
function syncSale(string $ticketUuid, string $customer, int $itemId, int $quantity, float $price, string $at, ?string $shiftUuid = null): array
{
    return [
        syncAction('ticket.create', ['ticket_uuid' => $ticketUuid, 'shift_uuid' => $shiftUuid, 'terminal_id' => 'POS', 'customer_name' => $customer, 'order_type' => 'dine_in'], $at),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticketUuid, 'line_uuid' => syncUuid(), 'item_id' => $itemId, 'quantity' => $quantity, 'unit_price' => $price], $at),
    ];
}

/** @return array<string, mixed> */
function syncPay(string $ticketUuid, float $amount, string $at, ?string $receiptNumber = null): array
{
    return syncAction('ticket.charge', ['ticket_uuid' => $ticketUuid, 'charges' => [
        ['charge_uuid' => syncUuid(), 'payment_method' => 'cash', 'amount' => $amount, 'tendered_amount' => $amount, 'receipt_number' => $receiptNumber],
    ]], $at);
}

test('two phones that both opened a shift offline end up on one shift', function () {
    $anna = syncPhone('cashier', 'POS-01');
    $ben = syncPhone('cashier', 'POS-02');
    $burger = posItem('Burger', 100);
    [$annaShift, $benShift] = [syncUuid(), syncUuid()];
    [$annaTicket, $benTicket] = [syncUuid(), syncUuid()];

    syncSend($anna['token'], [
        syncAction('shift.open', ['shift_uuid' => $annaShift, 'starting_cash' => 2000], '2026-10-10 10:00'),
        ...syncSale($annaTicket, 'John', $burger->id, 1, 100, '2026-10-10 10:10', $annaShift),
        syncPay($annaTicket, 100, '2026-10-10 10:15', "REC-2026-10-10-{$anna['device']->code}-001"),
    ])->assertOk();

    $results = syncSend($ben['token'], [
        syncAction('shift.open', ['shift_uuid' => $benShift, 'starting_cash' => 1500], '2026-10-10 10:02'),
        ...syncSale($benTicket, 'John', $burger->id, 1, 100, '2026-10-10 10:20', $benShift),
        syncPay($benTicket, 100, '2026-10-10 10:25', "REC-2026-10-10-{$ben['device']->code}-001"),
    ])->assertOk()->json('data.results');

    $shift = Shift::sole();
    expect($results[0]['result'])->toBe(['shift_id' => $shift->id, 'joined' => true])
        ->and(ShiftAlias::sole()->client_uuid)->toBe($benShift)
        ->and(Ticket::query()->pluck('shift_id')->unique()->all())->toBe([$shift->id])
        ->and(Ticket::query()->orderBy('id')->pluck('order_number')->all())->toBe(['#001', '#002'])
        // Two phones, two receipt booklets: the numbers never clash.
        ->and(Receipt::query()->orderBy('id')->pluck('receipt_number')->all())->toBe([
            "REC-2026-10-10-{$anna['device']->code}-001",
            "REC-2026-10-10-{$ben['device']->code}-001",
        ]);

    expect(SyncIssue::sole())
        ->type->toBe('starting_cash_conflict')
        ->shift_id->toBe($shift->id)
        ->details->toBe(['phone_starting_cash' => 1500, 'shift_starting_cash' => 2000]);
});

test('an offline shift opened while one was already open joins it', function () {
    $open = posOpenShift(posUser('manager'));
    $phone = syncPhone();

    syncSend($phone['token'], [syncAction('shift.open', ['shift_uuid' => syncUuid(), 'starting_cash' => 1000], '2026-10-10 17:00')])
        ->assertOk()
        ->assertJsonPath('data.results.0.status', 'applied')
        ->assertJsonPath('data.results.0.result.shift_id', $open->id);

    expect(Shift::count())->toBe(1);
});

test('joining a shift left open since an earlier day is flagged', function () {
    $this->travelTo(Carbon::parse('2026-10-09 09:00', 'Asia/Manila'));
    $old = posOpenShift(posUser('manager'));
    $this->travelTo(Carbon::parse('2026-10-10 18:00', 'Asia/Manila'));
    $phone = syncPhone();

    syncSend($phone['token'], [syncAction('shift.open', ['shift_uuid' => syncUuid(), 'starting_cash' => 1000], '2026-10-10 10:00')])
        ->assertJsonPath('data.results.0.result.shift_id', $old->id);

    expect(SyncIssue::query()->pluck('type')->sort()->values()->all())->toBe(['old_shift_joined']);
});

test('two phones selling the last one offline both count, stock goes below zero and is flagged', function () {
    $anna = syncPhone('cashier', 'POS-01');
    $ben = syncPhone('cashier', 'POS-02');
    syncMorningShift($anna['user']);
    $lastItik = posItem('Fried Itik', 450, 1);
    [$annaTicket, $benTicket] = [syncUuid(), syncUuid()];

    syncSend($anna['token'], [...syncSale($annaTicket, 'John', $lastItik->id, 1, 450, '2026-10-10 12:00'), syncPay($annaTicket, 450, '2026-10-10 12:05')])->assertOk();
    $results = syncSend($ben['token'], [...syncSale($benTicket, 'Mary', $lastItik->id, 1, 450, '2026-10-10 12:01'), syncPay($benTicket, 450, '2026-10-10 12:06')])
        ->assertOk()->json('data.results');

    expect($results[1]['status'])->toBe('applied_with_issue')
        ->and($results[1]['issues'][0]['type'])->toBe('stock_short')
        ->and($lastItik->fresh()->quantity)->toBe(-1)
        ->and($lastItik->fresh()->reserved_quantity)->toBe(0)
        ->and(Ticket::query()->where('status', 'paid')->count())->toBe(2);

    expect(SyncIssue::sole()->details)->toBe(['item' => 'Fried Itik', 'sold' => 1, 'available' => 0]);
});

test('a price changed during the outage: the price charged stands and is flagged', function () {
    $phone = syncPhone();
    syncMorningShift($phone['user']);
    $burger = posItem('Burger', 120);
    $ticket = syncUuid();

    syncSend($phone['token'], [...syncSale($ticket, 'John', $burger->id, 2, 100, '2026-10-10 12:00'), syncPay($ticket, 200, '2026-10-10 12:05')])->assertOk();

    $line = TicketItem::sole();
    expect((float) $line->unit_price)->toBe(100.0)
        ->and((float) $line->line_total)->toBe(200.0)
        ->and(Ticket::sole()->status)->toBe('paid');

    expect(SyncIssue::sole())
        ->type->toBe('price_changed')
        ->details->toBe(['item' => 'Burger', 'charged_price' => 100, 'menu_price' => 120]);
});

test('a payment that does not match the server total is recorded as paid, the gap booked as discount', function () {
    $phone = syncPhone();
    syncMorningShift($phone['user']);
    $burger = posItem('Burger', 100);
    $ticket = syncUuid();

    syncSend($phone['token'], [...syncSale($ticket, 'John', $burger->id, 2, 100, '2026-10-10 12:00'), syncPay($ticket, 190, '2026-10-10 12:05')])->assertOk();

    $paid = Ticket::sole();
    expect($paid->status)->toBe('paid')
        ->and((float) $paid->subtotal)->toBe(200.0)
        ->and((float) $paid->discount_amount)->toBe(10.0)
        ->and((float) $paid->total)->toBe(190.0)
        ->and((float) Receipt::sole()->payload['discount'])->toBe(10.0);

    expect(SyncIssue::sole())->type->toBe('charge_mismatch');
});

test('an offline payment for a ticket already paid elsewhere is not charged twice, it is flagged', function () {
    $manager = posUser('manager');
    $phone = syncPhone();
    $shift = posOpenShift($manager);
    $ticket = posTicket($shift, $phone['user'], 'John');
    posAddItem($ticket, posItem('Burger', 100));
    // The manager settles it at the counter while the cashier's phone is offline.
    posPay($ticket->fresh(), $manager, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    $result = syncSend($phone['token'], [syncAction('ticket.charge', ['ticket_id' => $ticket->id, 'charges' => [
        ['charge_uuid' => syncUuid(), 'payment_method' => 'gcash', 'amount' => 100, 'payment_reference' => 'GC-1'],
    ]], '2026-10-10 12:00')])->assertOk()->json('data.results.0');

    expect($result['status'])->toBe('applied_with_issue')
        ->and($result['result']['charged'])->toBeFalse()
        ->and(Charge::count())->toBe(1)
        ->and(SyncIssue::sole()->type)->toBe('possible_double_payment');
});

test('online actions through sync keep every rule', function () {
    $phone = syncPhone();
    posOpenShift($phone['user']);
    $lastOne = posItem('Fried Itik', 450, 0);
    $burger = posItem('Burger', 100);
    $ticket = syncUuid();

    $results = syncSend($phone['token'], [
        syncAction('ticket.create', ['ticket_uuid' => $ticket, 'terminal_id' => 'POS', 'customer_name' => 'John', 'order_type' => 'dine_in']),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => syncUuid(), 'item_id' => $lastOne->id, 'quantity' => 1]),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => syncUuid(), 'item_id' => $burger->id, 'quantity' => 1, 'unit_price' => 1]),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => $line = syncUuid(), 'item_id' => $burger->id, 'quantity' => 1]),
        syncAction('ticket.void_item', ['line_uuid' => $line, 'reason' => 'x']),
    ])->assertOk()->json('data.results');

    expect(collect($results)->pluck('status')->all())->toBe(['applied', 'rejected', 'rejected', 'applied', 'rejected'])
        ->and($results[1]['message'])->toBe('Insufficient stock for item Fried Itik.')
        ->and($results[2]['message'])->toBe('Item Burger does not accept this price/name combination.')
        ->and($results[4]['message'])->toContain('needs a manager passcode')
        // Online, the cashier saw the refusal on the spot: nothing for a manager to review.
        ->and(SyncIssue::count())->toBe(0)
        ->and(TicketItem::sole()->added_offline)->toBeFalse()
        ->and(TicketItem::sole()->completed_at)->toBeNull();
});

test('an offline action that cannot be applied is rejected and flagged for a manager', function () {
    $phone = syncPhone();
    posOpenShift($phone['user']);

    $result = syncSend($phone['token'], [
        syncAction('ticket.add_item', ['ticket_uuid' => syncUuid(), 'line_uuid' => syncUuid(), 'item_id' => posItem('Burger', 100)->id, 'quantity' => 1], '2026-10-10 12:00'),
    ])->assertOk()->json('data.results.0');

    expect($result['status'])->toBe('rejected')
        ->and($result['message'])->toBe('That ticket never reached the server.')
        ->and(SyncIssue::sole()->type)->toBe('rejected_action');
});

test('a cashier cannot reach another cashier\'s ticket through sync', function () {
    $owner = posUser();
    $phone = syncPhone();
    $ticket = posTicket(posOpenShift($owner), $owner, 'John');

    syncSend($phone['token'], [syncAction('ticket.discount', ['ticket_id' => $ticket->id, 'discount_amount' => 50])])
        ->assertJsonPath('data.results.0.status', 'rejected')
        ->assertJsonPath('data.results.0.message', 'Not found.');

    expect((float) $ticket->fresh()->discount_amount)->toBe(0.0);
});

test('only a manager records cash entries through sync', function () {
    $phone = syncPhone();
    posOpenShift($phone['user']);

    syncSend($phone['token'], [syncAction('shift_transaction.add', ['transaction_uuid' => syncUuid(), 'type' => 'expense', 'amount' => 50, 'reason' => 'Ice'])])
        ->assertJsonPath('data.results.0.status', 'rejected')
        ->assertJsonPath('data.results.0.message', 'Only a manager or admin can record cash additions and expenses.');
});

test('a phone clock in the future or before the shift opened is corrected and flagged', function () {
    $phone = syncPhone();
    $shift = posOpenShift($phone['user']);
    $this->travelTo(Carbon::parse('2026-10-10 18:00', 'Asia/Manila'));
    $shift->update(['opened_at' => Carbon::parse('2026-10-10 09:00', 'Asia/Manila')]);
    [$early, $late] = [syncUuid(), syncUuid()];

    syncSend($phone['token'], [
        syncAction('ticket.create', ['ticket_uuid' => $early, 'terminal_id' => 'POS', 'customer_name' => 'Early', 'order_type' => 'dine_in'], '2026-10-10 08:00'),
        syncAction('ticket.create', ['ticket_uuid' => $late, 'terminal_id' => 'POS', 'customer_name' => 'Late', 'order_type' => 'dine_in'], '2026-10-11 09:00'),
    ])->assertOk();

    expect(Ticket::firstWhere('customer_name', 'Early')->created_at->setTimezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2026-10-10 09:00')
        ->and(Ticket::firstWhere('customer_name', 'Late')->created_at->setTimezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2026-10-10 18:00')
        ->and(SyncIssue::query()->pluck('type')->all())->toBe(['clock_wrong', 'clock_wrong']);
});

test('the shift cannot close while a phone still holds offline actions; a manager can force it', function () {
    $manager = posUser('manager');
    $phone = syncPhone();
    $shift = posOpenShift($manager);
    syncSend($phone['token'], [], pending: 3)->assertOk();

    app('auth')->forgetGuards();
    Sanctum::actingAs($phone['user'], ['*']);
    $this->getJson('/api/v1/shifts/active')
        ->assertOk()
        ->assertJsonPath('data.devices.0.code', $phone['device']->code)
        ->assertJsonPath('data.devices.0.pending_actions', 3);

    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000])
        ->assertConflict()
        ->assertJsonPath('message', "{$phone['device']->code} ({$phone['user']->name}) still has 3 offline action(s) to sync.");

    // A cashier can't force it.
    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000, 'force' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['force']);

    Sanctum::actingAs($manager, ['*']);
    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000, 'force' => true])->assertOk();

    expect($shift->fresh()->status)->toBe('closed');
});

test('once the phone has sent everything the shift closes normally', function () {
    $phone = syncPhone();
    $shift = posOpenShift($phone['user']);
    syncSend($phone['token'], [], pending: 2)->assertOk();
    syncSend($phone['token'], [], pending: 0)->assertOk();

    app('auth')->forgetGuards();
    Sanctum::actingAs($phone['user'], ['*']);
    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000])->assertOk();
});

test('sales that arrive after their shift closed land on it and are flagged; the closing totals stay', function () {
    $manager = posUser('manager');
    $phone = syncPhone();
    $shift = posOpenShift($manager);
    $shift->update(['opened_at' => Carbon::parse('2026-10-10 09:00', 'Asia/Manila')]);
    app(ShiftService::class)->CloseShift($shift, $manager, 1000);
    $closedTotals = $shift->fresh()->only(['total_revenue', 'expected_cash']);
    $ticket = syncUuid();
    $burger = posItem('Burger', 100);

    syncSend($phone['token'], [
        syncAction('shift.open', ['shift_uuid' => $phoneShift = syncUuid(), 'starting_cash' => 1000], '2026-10-10 12:00'),
        ...syncSale($ticket, 'John', $burger->id, 1, 100, '2026-10-10 12:10', $phoneShift),
        syncPay($ticket, 100, '2026-10-10 12:15'),
    ])->assertOk();

    expect(Ticket::sole()->shift_id)->toBe($shift->id)
        ->and(Ticket::sole()->status)->toBe('paid')
        ->and($shift->fresh()->only(['total_revenue', 'expected_cash']))->toBe($closedTotals)
        ->and(SyncIssue::query()->pluck('type')->unique()->values()->all())->toBe(['synced_after_close']);
});

test('signing in gives a POS phone a device code; a kitchen display gets none', function () {
    $user = posUser();

    $code = $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'password', 'device_name' => 'POS-01'])
        ->assertCreated()
        ->json('data.device.code');
    $second = $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'password', 'device_name' => 'POS-02'])
        ->json('data.device.code');

    expect($code)->toStartWith('P')->and($second)->not->toBe($code);

    $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'password', 'scope' => 'kds'])
        ->assertCreated()
        ->assertJsonPath('data.device', null);
});

test('a malformed batch is refused as a whole; a bad action alone is rejected on its own', function () {
    $phone = syncPhone();
    posOpenShift($phone['user']);

    syncSend($phone['token'], [['id' => 'not-a-uuid', 'type' => 'ticket.fly', 'offline' => true, 'data' => []]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['actions.0.id', 'actions.0.type', 'actions.0.happened_at']);

    syncSend($phone['token'], [
        syncAction('ticket.create', ['ticket_uuid' => syncUuid(), 'terminal_id' => 'POS', 'customer_name' => 'John', 'order_type' => 'delivery']),
        syncAction('ticket.create', ['ticket_uuid' => syncUuid(), 'terminal_id' => 'POS', 'customer_name' => 'Mary', 'order_type' => 'dine_in']),
    ])
        ->assertOk()
        ->assertJsonPath('data.results.0.status', 'rejected')
        ->assertJsonPath('data.results.0.message', 'The selected order type is invalid.')
        ->assertJsonPath('data.results.1.status', 'applied');
});

test('reusing a phone id under a new action is rejected, not applied twice', function () {
    $phone = syncPhone();
    posOpenShift($phone['user']);
    $ticket = syncUuid();
    $create = fn (): array => syncAction('ticket.create', ['ticket_uuid' => $ticket, 'terminal_id' => 'POS', 'customer_name' => 'John', 'order_type' => 'dine_in']);

    syncSend($phone['token'], [$create()])->assertJsonPath('data.results.0.status', 'applied');
    syncSend($phone['token'], [$create()])
        ->assertJsonPath('data.results.0.status', 'rejected')
        ->assertJsonPath('data.results.0.message', 'Something with this id was already received from another action.');

    expect(Ticket::count())->toBe(1);
});

test('sync needs a real POS login', function () {
    Sanctum::actingAs(posUser(), ['*']);

    $this->postJson('/api/v1/sync', ['actions' => []])->assertForbidden();
    $this->getJson('/api/v1/sync/snapshot')->assertForbidden();
});
