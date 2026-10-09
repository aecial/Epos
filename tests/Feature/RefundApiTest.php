<?php

use App\Models\Refund;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use App\Services\ShiftService;
use Laravel\Sanctum\Sanctum;

/*
| The POS refund list: what a manager's terminal reads to find refunds waiting for their
| passcode. Every terminal sees every refund; it filters by status and shift, newest first.
*/

/** A paid ticket of 2 Burgers (₱200 cash). */
function refundApiPaidTicket(Shift $shift, User $cashier, string $customer): Ticket
{
    $ticket = posTicket($shift, $cashier, $customer);
    posAddItem($ticket, posItem('Burger '.uniqid(), 100), 2);

    return posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200]]);
}

function refundApiRequest(Ticket $ticket, User $by, float $amount = 100): Refund
{
    return app(RefundService::class)->RequestRefund($ticket, $ticket->charges()->firstOrFail(), $by, [
        ['ticket_item_id' => $ticket->items()->firstOrFail()->id, 'quantity' => 1, 'amount' => $amount],
    ], 'Cold');
}

/**
 * Yesterday's shift: one approved refund. Today's shift: one rejected, then one pending.
 *
 * @return array{old: Shift, today: Shift, approved: Refund, rejected: Refund, pending: Refund}
 */
function refundApiScenario(): array
{
    $anna = posUser();
    posUser('manager');
    $refunds = app(RefundService::class);

    $old = posOpenShift($anna);
    $approved = refundApiRequest(refundApiPaidTicket($old, $anna, 'john'), $anna);
    $refunds->ApproveRefund($approved, $anna, '1234');
    app(ShiftService::class)->CloseShift($old, $anna, 1100);

    test()->travel(1)->days();
    $today = posOpenShift($anna);
    $rejected = refundApiRequest(refundApiPaidTicket($today, $anna, 'mary'), $anna, 50);
    $refunds->RejectRefund($rejected, $anna, '1234');
    test()->travel(5)->minutes();
    $pending = refundApiRequest(refundApiPaidTicket($today, $anna, 'pedro'), $anna, 80);

    return ['old' => $old, 'today' => $today, 'approved' => $approved->fresh(), 'rejected' => $rejected->fresh(), 'pending' => $pending];
}

test('every refund is listed newest first with its lines', function () {
    ['approved' => $approved, 'rejected' => $rejected, 'pending' => $pending] = refundApiScenario();
    Sanctum::actingAs(posUser(), ['*']);

    $data = $this->getJson('/api/v1/refunds')->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$pending->id, $rejected->id, $approved->id])
        ->and($data[0]['status'])->toBe('pending')
        ->and((float) $data[0]['amount'])->toBe(80.0)
        ->and($data[0]['items'])->toHaveCount(1)
        ->and($data[0]['items'][0]['quantity'])->toBe(1);
});

test('the list filters by status', function (string $status) {
    $scenario = refundApiScenario();
    Sanctum::actingAs(posUser(), ['*']);

    $this->getJson("/api/v1/refunds?status={$status}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $scenario[$status]->id);
})->with(['pending', 'approved', 'rejected']);

test('the list filters by shift', function () {
    ['old' => $old, 'today' => $today, 'approved' => $approved, 'rejected' => $rejected, 'pending' => $pending] = refundApiScenario();
    Sanctum::actingAs(posUser(), ['*']);

    expect(collect($this->getJson("/api/v1/refunds?shift_id={$today->id}")->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$pending->id, $rejected->id]);
    expect(collect($this->getJson("/api/v1/refunds?shift_id={$old->id}&status=approved")->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$approved->id]);
});

test('an unknown status or shift is rejected', function () {
    Sanctum::actingAs(posUser(), ['*']);

    $this->getJson('/api/v1/refunds?status=refunded')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->getJson('/api/v1/refunds?shift_id=999')->assertUnprocessable()->assertJsonValidationErrors(['shift_id']);
});

test('one refund reads back with its lines', function () {
    ['pending' => $pending] = refundApiScenario();
    Sanctum::actingAs(posUser(), ['*']);

    $this->getJson("/api/v1/refunds/{$pending->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $pending->id)
        ->assertJsonPath('data.reason', 'Cold')
        ->assertJsonCount(1, 'data.items');

    $this->getJson('/api/v1/refunds/999')->assertNotFound();
});
