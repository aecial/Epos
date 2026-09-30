<?php

use Laravel\Sanctum\Sanctum;

/*
| RefundService authoritatively re-derives what a ticket item can still be refunded for
| (the passcode gate at approval only proves WHO is approving, never whether the refund's
| contents are legitimate) - the client-supplied ticket_item_id, quantity and amount are
| never trusted at face value.
*/

test('a ticket_item_id that belongs to a different ticket is rejected', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);

    $ticketA = posTicket($shift, $cashier, 'john');
    posAddItem($ticketA, $item, 1);
    $ticketB = posTicket($shift, $cashier, 'jane');
    $lineB = posAddItem($ticketB, $item, 1);

    Sanctum::actingAs($cashier);
    $chargeA = $this->postJson("/api/v1/tickets/{$ticketA->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]],
    ])->json('data.charges.0.id');

    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticketA->id,
        'charge_id' => $chargeA,
        'items' => [['ticket_item_id' => $lineB->id, 'quantity' => 1, 'amount' => 100]],
    ])->assertStatus(409);
});

test('refund quantity cannot exceed what was purchased on the line', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 2);

    Sanctum::actingAs($cashier);
    $charge = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200]],
    ])->json('data.charges.0.id');

    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 999, 'amount' => 200]],
    ])->assertStatus(409);

    // Payment already deducted the 2 purchased units (10 - 2); the rejected refund must not
    // touch stock at all.
    expect($item->fresh()->quantity)->toBe(8);
});

test('refund amount cannot exceed the purchased value of the requested quantity', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier);
    $charge = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]],
    ])->json('data.charges.0.id');

    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 99999]],
    ])->assertStatus(409);
});

test('the same purchased units cannot be refunded twice across separate refund requests', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $manager, 'john');
    $line = posAddItem($ticket, $item, 2);

    Sanctum::actingAs($manager);
    $charge = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200]],
    ])->json('data.charges.0.id');

    $refund1 = $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 2, 'amount' => 200]],
    ])->json('data.id');

    $this->putJson("/api/v1/refunds/{$refund1}/approve", ['passcode' => '1234'])->assertOk();
    expect($item->fresh()->quantity)->toBe(10);

    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 2, 'amount' => 200]],
    ])->assertStatus(409);

    // Stock and the shift's cash reconciliation must reflect only the one legitimate refund.
    expect($item->fresh()->quantity)->toBe(10);
});

test('a still-pending refund also blocks a duplicate request for the same units', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $manager, 'john');
    $line = posAddItem($ticket, $item, 2);

    Sanctum::actingAs($manager);
    $charge = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200]],
    ])->json('data.charges.0.id');

    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 2, 'amount' => 200]],
    ])->assertStatus(201);

    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 100]],
    ])->assertStatus(409);
});

test('rejecting a refund frees up the quantity for a legitimate new request', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $manager, 'john');
    $line = posAddItem($ticket, $item, 2);

    Sanctum::actingAs($manager);
    $charge = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200]],
    ])->json('data.charges.0.id');

    $refund1 = $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 2, 'amount' => 200]],
    ])->json('data.id');

    $this->putJson("/api/v1/refunds/{$refund1}/reject", ['passcode' => '1234'])->assertOk();

    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 2, 'amount' => 200]],
    ])->assertStatus(201);
});

test('a refund cannot claim more than what its specific charge collected in a split payment', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $item = posItem('Burger', 200, quantity: 10);
    $ticket = posTicket($shift, $manager, 'john');
    $line = posAddItem($ticket, $item, 1);

    Sanctum::actingAs($manager);
    $charges = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100],
            ['payment_method' => 'gcash', 'amount' => 100, 'payment_reference' => 'GC-1'],
        ],
    ])->json('data.charges');
    $cashChargeId = $charges[0]['id'];

    // The cash charge only collected 100, even though the item line is worth 200 overall.
    $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $cashChargeId,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 150]],
    ])->assertStatus(409);
});

test('a legitimate refund within bounds is accepted and restores stock on approval', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $manager, 'john');
    $line = posAddItem($ticket, $item, 2);

    Sanctum::actingAs($manager);
    $charge = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200]],
    ])->json('data.charges.0.id');

    $refund = $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $charge,
        'items' => [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 100]],
    ])->assertStatus(201)->json('data.id');

    $this->putJson("/api/v1/refunds/{$refund}/approve", ['passcode' => '1234'])->assertOk();

    expect($item->fresh()->quantity)->toBe(9);
});
