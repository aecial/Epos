<?php

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

test('a cashier can lower a line quantity without a passcode, releasing the difference', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $burger, 3);

    expect($burger->fresh()->reserved_quantity)->toBe(3);

    Sanctum::actingAs($cashier);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 1])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 1)
        ->assertJsonPath('data.subtotal', 100)
        ->assertJsonPath('data.total', 100);

    expect($burger->fresh()->reserved_quantity)->toBe(1)
        ->and((float) $line->fresh()->line_total)->toBe(100.0);
});

test('a cashier can raise a line quantity without a passcode, reserving more', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $burger, 1);

    Sanctum::actingAs($cashier);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 4])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 4)
        ->assertJsonPath('data.total', 400);

    expect($burger->fresh()->reserved_quantity)->toBe(4);
});

test('raising the quantity past available stock is rejected and nothing changes', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100, quantity: 3);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $burger, 2);

    Sanctum::actingAs($cashier);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 10])
        ->assertStatus(409);

    expect($burger->fresh()->reserved_quantity)->toBe(2)
        ->and($line->fresh()->quantity)->toBe(2);
});

test('quantity cannot be dropped to zero through this endpoint; voiding is required', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $burger, 2);

    Sanctum::actingAs($cashier);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quantity');
});

test('a voided line cannot have its quantity changed', function () {
    $cashier = posUser();
    posUser('manager'); // resolves the passcode below: every posUser() gets '1234' by default.
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $burger, 2);

    app(TicketService::class)->VoidItem($line, $cashier, '1234');

    Sanctum::actingAs($cashier);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 1])
        ->assertStatus(409);
});

test('quantity cannot be changed once the ticket is no longer open', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $burger, 2);

    app(TicketService::class)->CancelTicket($ticket, $cashier);

    Sanctum::actingAs($cashier);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 1])
        ->assertStatus(409);
});

test('changing quantity recomputes the total on a line with modifiers', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $group = ModifierGroup::create(['name' => 'Add-ons']);
    $cheese = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Extra cheese']);
    $burger->modifiers()->attach($cheese->id, ['price_modifier' => 20, 'status' => 'active', 'display_order' => 1]);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = app(TicketService::class)->AddItem($ticket, $burger->fresh(), 2, [$cheese->id]);

    Sanctum::actingAs($cashier);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 3])
        ->assertOk()
        ->assertJsonPath('data.items.0.line_total', 360); // (100 + 20) * 3
});

test('the quantity endpoint requires authentication', function () {
    $this->patchJson('/api/v1/tickets/1/items/1', ['quantity' => 1])->assertUnauthorized();
});
