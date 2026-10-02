<?php

use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

/*
| PATCH /api/v1/kds/orders/items/{ticketItem}/complete - toggles ticket_items.completed_at.
| No passcode: not a removal. Not audit data - kds:clear-completed (separate command) sweeps
| it back to null nightly regardless of ticket status.
*/

test('marking an item complete sets completed_at', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => true])
        ->assertOk()
        ->assertJsonPath('data.ticket_item_id', $line->id)
        ->assertJsonPath('data.completed', true);

    expect($line->fresh()->completed_at)->not->toBeNull();
});

test('marking an item incomplete clears completed_at', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);
    $line->update(['completed_at' => now()]);

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => false])
        ->assertOk()
        ->assertJsonPath('data.completed', false);

    expect($line->fresh()->completed_at)->toBeNull();
});

test('completion state cannot be changed on a voided item', function () {
    $cashier = posUser();
    posUser('manager');
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);
    app(TicketService::class)->VoidItem($line, $cashier, '1234');

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => true])
        ->assertStatus(409);
});

test('completion state cannot be changed once the ticket is no longer open', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);
    posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => true])
        ->assertStatus(409);
});

test('the completion endpoint requires authentication', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);

    $this->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => true])
        ->assertUnauthorized();
});

test('completed requires a boolean', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => 'yes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('completed');
});

/*
| PATCH /api/v1/kds/orders/{ticket}/complete - bumps every pending line on the ticket at once
| (the "tap the customer name" gesture), instead of toggling items one by one.
*/

test('completing a whole ticket marks every pending line done', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $burger = posAddItem($ticket, $item, 1);
    $rice = posAddItem($ticket, $item, 2);

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/{$ticket->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.ticket_id', $ticket->id)
        ->assertJsonPath('data.completed', true);

    expect($burger->fresh()->completed_at)->not->toBeNull();
    expect($rice->fresh()->completed_at)->not->toBeNull();
});

test('completing a whole ticket leaves voided lines untouched', function () {
    $cashier = posUser();
    posUser('manager');
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $kept = posAddItem($ticket, $item, 1);
    $voided = posAddItem($ticket, $item, 1);
    app(TicketService::class)->VoidItem($voided, $cashier, '1234');

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/{$ticket->id}/complete")->assertOk();

    expect($kept->fresh()->completed_at)->not->toBeNull();
    expect($voided->fresh()->completed_at)->toBeNull();
});

test('whole-ticket completion cannot be used once the ticket is no longer open', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);
    posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/{$ticket->id}/complete")->assertStatus(409);
});

test('the whole-ticket completion endpoint requires authentication', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    $this->patchJson("/api/v1/kds/orders/{$ticket->id}/complete")->assertUnauthorized();
});
