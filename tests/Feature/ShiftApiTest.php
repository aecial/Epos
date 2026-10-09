<?php

use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use App\Services\ShiftTransactionService;
use Laravel\Sanctum\Sanctum;

/*
| Shift open/close over the POS API: one open shift at a time, live totals while open, and a
| closing snapshot of Starting Cash + Cash Sales + Additions − Expenses − Cash Refunds.
*/

/**
 * A shift opened with ₱2,000 and a day's worth of drawer movement:
 * - John: 3 Burgers (₱300) in cash, ₱100 of it refunded in cash (approved)
 * - Mary: 2 Burgers (₱200) by GCash
 * - Pedro: 2 Burgers (₱200) split ₱100 cash + ₱100 GCash
 * - a ₱500 cash addition and a ₱150 expense, plus a ₱999 expense that was deleted
 * Expected cash = 2000 + 400 + 500 − 150 − 100 = 2650.
 *
 * @return array{shift: Shift, cashier: User, manager: User}
 */
function shiftApiBusyDay(): array
{
    $cashier = posUser();
    $manager = posUser('manager');

    Sanctum::actingAs($cashier, ['*']);
    $shiftId = test()->postJson('/api/v1/shifts', ['starting_cash' => 2000])->assertCreated()->json('data.id');
    $shift = Shift::findOrFail($shiftId);
    $burger = posItem('Burger', 100);

    $john = posTicket($shift, $cashier, 'john');
    posAddItem($john, $burger, 3);
    $john = posPay($john->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 300, 'tendered_amount' => 500]]);

    $mary = posTicket($shift, $cashier, 'mary');
    posAddItem($mary, $burger, 2);
    posPay($mary->fresh(), $cashier, [['payment_method' => 'gcash', 'amount' => 200, 'payment_reference' => 'GC-1']]);

    $pedro = posTicket($shift, $cashier, 'pedro');
    posAddItem($pedro, $burger, 2);
    posPay($pedro->fresh(), $cashier, [
        ['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100],
        ['payment_method' => 'gcash', 'amount' => 100, 'payment_reference' => 'GC-2'],
    ]);

    $transactions = app(ShiftTransactionService::class);
    $transactions->AddTransaction($shift, $manager, 'addition', 500, 'Change fund');
    $transactions->AddTransaction($shift, $manager, 'expense', 150, 'Ice');
    $transactions->DeleteTransaction($transactions->AddTransaction($shift, $manager, 'expense', 999, 'Typo'), $manager);

    $refunds = app(RefundService::class);
    $refund = $refunds->RequestRefund($john, $john->charges()->firstOrFail(), $cashier, [
        ['ticket_item_id' => $john->items()->firstOrFail()->id, 'quantity' => 1, 'amount' => 100],
    ], 'Cold');
    $refunds->ApproveRefund($refund, $cashier, '1234');

    return ['shift' => $shift->fresh(), 'cashier' => $cashier, 'manager' => $manager];
}

test('a cashier opens a shift with the starting cash', function () {
    $cashier = posUser();
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson('/api/v1/shifts', ['starting_cash' => 2000])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.opened_by', $cashier->id);

    $shift = Shift::sole();
    expect((float) $shift->starting_cash)->toBe(2000.0)
        ->and($shift->opened_at)->not->toBeNull();
});

test('only one shift can be open at a time', function () {
    posOpenShift(posUser());
    Sanctum::actingAs(posUser(), ['*']);

    $this->postJson('/api/v1/shifts', ['starting_cash' => 500])
        ->assertConflict()
        ->assertJsonPath('message', 'A shift is already open.');

    expect(Shift::count())->toBe(1);
});

test('opening needs a starting cash of zero or more', function (array $body) {
    Sanctum::actingAs(posUser(), ['*']);

    $this->postJson('/api/v1/shifts', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starting_cash']);
})->with([
    'missing' => [[]],
    'negative' => [['starting_cash' => -1]],
    'not a number' => [['starting_cash' => 'abc']],
]);

test('the active shift is 404 when none is open', function () {
    Sanctum::actingAs(posUser(), ['*']);

    $this->getJson('/api/v1/shifts/active')
        ->assertNotFound()
        ->assertJsonPath('message', 'No active shift is open.');
});

test('the active shift shows live totals and the expected drawer cash', function () {
    ['shift' => $shift] = shiftApiBusyDay();

    $data = $this->getJson('/api/v1/shifts/active')->assertOk()->json('data');

    expect($data['id'])->toBe($shift->id)
        ->and($data['total_revenue'])->toEqual(700)
        ->and($data['total_cash'])->toEqual(400)
        ->and($data['total_gcash'])->toEqual(300)
        ->and($data['total_additions'])->toEqual(500)
        ->and($data['total_expenses'])->toEqual(150)
        ->and($data['total_refunds'])->toEqual(100)
        ->and($data['total_cash_refunds'])->toEqual(100)
        ->and($data['expected_cash'])->toEqual(2650);

    // The same live figures by id while it's open.
    expect($this->getJson("/api/v1/shifts/{$shift->id}")->assertOk()->json('data.expected_cash'))->toEqual(2650);
});

test('closing snapshots the totals and the drawer shortage', function () {
    ['shift' => $shift, 'cashier' => $cashier] = shiftApiBusyDay();

    $data = $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 2600])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.closed_by', $cashier->id)
        ->json('data');

    expect($data['total_revenue'])->toEqual(700)
        ->and($data['total_cash'])->toEqual(400)
        ->and($data['total_gcash'])->toEqual(300)
        ->and($data['total_additions'])->toEqual(500)
        ->and($data['total_expenses'])->toEqual(150)
        ->and($data['total_refunds'])->toEqual(100)
        ->and($data['expected_cash'])->toEqual(2650)
        ->and($data['closing_cash'])->toEqual(2600)
        ->and($data['discrepancy'])->toEqual(-50);

    $closed = $shift->fresh();
    expect($closed->status)->toBe('closed')
        ->and($closed->closed_at)->not->toBeNull()
        ->and((float) $closed->expected_cash)->toBe(2650.0)
        ->and((float) $closed->discrepancy)->toBe(-50.0);

    // A closed shift is read back from its snapshot, and no shift is active any more.
    expect((float) $this->getJson("/api/v1/shifts/{$shift->id}")->assertOk()->json('data.expected_cash'))->toBe(2650.0);
    $this->getJson('/api/v1/shifts/active')->assertNotFound();
});

test('an over-count shows as a positive discrepancy', function () {
    ['shift' => $shift] = shiftApiBusyDay();

    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 2700])
        ->assertOk();

    expect((float) $shift->fresh()->discrepancy)->toBe(50.0);
});

test('a shift with an open ticket cannot close until it is paid or cancelled', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100));
    Sanctum::actingAs($cashier, ['*']);

    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000])
        ->assertConflict()
        ->assertJsonPath('message', 'Shift has open tickets that must be paid or cancelled first.');

    expect($shift->fresh()->status)->toBe('open');

    $this->postJson("/api/v1/tickets/{$ticket->id}/cancel")->assertOk();

    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    // Nothing sold: the drawer should hold exactly the starting cash.
    expect((float) $shift->fresh()->expected_cash)->toBe(1000.0)
        ->and((float) $shift->fresh()->discrepancy)->toBe(0.0);
});

test('a closed shift cannot be closed again, and a new one can then be opened', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000])->assertOk();

    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 900])
        ->assertConflict()
        ->assertJsonPath('message', 'Shift is already closed.');

    expect((float) $shift->fresh()->closing_cash)->toBe(1000.0);

    $this->postJson('/api/v1/shifts', ['starting_cash' => 1500])->assertCreated();
    expect(Shift::where('status', 'open')->count())->toBe(1);
});

test('closing needs the counted cash', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->putJson("/api/v1/shifts/{$shift->id}/close", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['closing_cash']);

    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => -5])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['closing_cash']);

    expect($shift->fresh()->status)->toBe('open');
});

test('an unknown shift is 404', function () {
    Sanctum::actingAs(posUser(), ['*']);

    $this->getJson('/api/v1/shifts/999')->assertNotFound();
    $this->putJson('/api/v1/shifts/999/close', ['closing_cash' => 0])->assertNotFound();
});

test('a new ticket needs an open shift', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    Sanctum::actingAs($cashier, ['*']);
    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1000])->assertOk();

    $this->postJson('/api/v1/tickets', ['terminal_id' => 'POS-01', 'customer_name' => 'john', 'order_type' => 'dine_in'])
        ->assertConflict()
        ->assertJsonPath('message', 'No active shift is open.');

    expect(Ticket::count())->toBe(0);
});
