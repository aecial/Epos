<?php

use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

/*
| A ticket discounted all the way to ₱0 (a comp/freebie) still needs to be closeable: the
| food was made and given away, so inventory must still be deducted, even though no money
| changes hands. It's closed the same way as any other ticket - POST .../charges - just with
| a single zero-amount charge instead of a real payment.
*/

test('a 100%-discounted ticket is closed with a single $0 charge, and stock is still deducted', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 2); // subtotal 200
    app(TicketService::class)->SetDiscount($ticket, 0, 100); // 100% off -> total 0

    Sanctum::actingAs($cashier);

    $response = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 0]],
    ])->assertOk();

    $response
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.subtotal', 200)
        ->assertJsonPath('data.total', 0)
        ->assertJsonCount(1, 'data.charges')
        ->assertJsonPath('data.charges.0.amount', 0);

    // Food was made and handed over, so stock still comes off the shelf.
    expect((int) $item->fresh()->quantity)->toBe(8)
        ->and((int) $item->fresh()->reserved_quantity)->toBe(0);

    // The receipt records the full subtotal was comped, not that nothing was ordered.
    $payload = $response->json('data.charges.0.receipt.payload');
    expect($payload['subtotal'])->toEqual(200)
        ->and($payload['discount'])->toEqual(200)
        ->and($payload['total'])->toEqual(0);
});

test('a ticket discounted to $0 via a fixed amount larger than the subtotal can also be comped', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 50);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1); // subtotal 50
    app(TicketService::class)->SetDiscount($ticket, 999, 0); // fixed discount far exceeds subtotal

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 0]],
    ])->assertOk()->assertJsonPath('data.total', 0);
});

test('a comped ticket does not inflate the shift\'s cash sales total', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier); // starting cash 1000
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);
    app(TicketService::class)->SetDiscount($ticket, 0, 100);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 0]],
    ])->assertOk();

    $this->getJson('/api/v1/shifts/active')
        ->assertOk()
        ->assertJsonPath('data.total_revenue', 0)
        ->assertJsonPath('data.total_cash', 0)
        ->assertJsonPath('data.expected_cash', 1000); // unchanged - nothing was collected
});

test('two zero-amount charges against a $0 total are rejected, not a server error', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);
    app(TicketService::class)->SetDiscount($ticket, 0, 100);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 0],
            ['payment_method' => 'cash', 'amount' => 0],
        ],
    ])->assertStatus(409);

    expect($ticket->fresh()->status)->toBe('open');
});

test('a zero-amount charge against a real (non-zero) total is still rejected', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $startingQuantity = (int) $item->quantity;
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1); // total 100, no discount

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 0]],
    ])->assertStatus(409);

    expect($ticket->fresh()->status)->toBe('open')
        ->and((int) $item->fresh()->quantity)->toBe($startingQuantity); // untouched, never deducted
});

test('a $0 charge cannot be split across cash and gcash to sneak past the single-charge rule', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);
    app(TicketService::class)->SetDiscount($ticket, 0, 100);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 0],
            ['payment_method' => 'gcash', 'amount' => 0, 'payment_reference' => 'GC-0'],
        ],
    ])->assertStatus(409);
});
