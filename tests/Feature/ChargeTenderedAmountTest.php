<?php

use Laravel\Sanctum\Sanctum;

/*
| GCash is a digital payment - it has no concept of "tendered cash" or "change due". Without
| this rule, a gcash charge could carry a tendered_amount/change_due that gets baked straight
| into the immutable receipt payload next to the GCash reference, looking fabricated to
| anyone reading the receipt later.
*/

test('a gcash charge with a tendered_amount is rejected', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [[
            'payment_method' => 'gcash', 'amount' => 100, 'tendered_amount' => 500, 'payment_reference' => 'GC-1',
        ]],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('charges.0.tendered_amount');

    expect($ticket->fresh()->status)->toBe('open');
});

test('a cash charge with a tendered_amount is unaffected', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 150]],
    ])
        ->assertOk()
        ->assertJsonPath('data.charges.0.tendered_amount', 150)
        ->assertJsonPath('data.charges.0.change_due', 50);
});

test('a gcash charge with no tendered_amount is unaffected', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'gcash', 'amount' => 100, 'payment_reference' => 'GC-2']],
    ])
        ->assertOk()
        ->assertJsonPath('data.charges.0.tendered_amount', null)
        ->assertJsonPath('data.charges.0.change_due', null);
});

test('in a split payment, only the gcash line needs to omit tendered_amount', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 200);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    // The cash half keeps its tendered_amount; only the gcash half is bad. Catches a naive
    // fix that checks the request globally instead of per charge.
    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100],
            ['payment_method' => 'gcash', 'amount' => 100, 'tendered_amount' => 100, 'payment_reference' => 'GC-3'],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('charges.1.tendered_amount')
        ->assertJsonMissingValidationErrors('charges.0.tendered_amount');

    expect($ticket->fresh()->status)->toBe('open');
});
