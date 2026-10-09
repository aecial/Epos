<?php

use App\Models\Ticket;
use App\Services\ShiftService;
use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

/*
| Opening, discounting and cancelling tickets over the POS API: per-shift order numbers,
| duplicate customer names across terminals (john → john2 → john3), fixed or percent
| discounts, and a cancel that hands reserved stock back.
*/

/** @return array<string, string> */
function ticketApiBody(string $customer, string $terminal = 'POS-01', string $orderType = 'dine_in'): array
{
    return ['terminal_id' => $terminal, 'customer_name' => $customer, 'order_type' => $orderType];
}

test('a cashier opens a ticket on the active shift with the next order number', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson('/api/v1/tickets', ticketApiBody('  John  ', 'POS-02', 'takeout'))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.shift_id', $shift->id)
        ->assertJsonPath('data.created_by', $cashier->id)
        ->assertJsonPath('data.customer_name', 'John')
        ->assertJsonPath('data.order_number', '#001')
        ->assertJsonPath('data.order_type', 'takeout')
        ->assertJsonPath('data.terminal_id', 'POS-02')
        ->assertJsonPath('data.status', 'open');

    $this->postJson('/api/v1/tickets', ticketApiBody('Mary'))
        ->assertCreated()
        ->assertJsonPath('data.order_number', '#002');
});

test('order numbers restart with each shift', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    app(TicketService::class)->CancelTicket(posTicket($shift, $cashier, 'john'), $cashier);
    app(ShiftService::class)->CloseShift($shift, $cashier, 1000);
    posOpenShift($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson('/api/v1/tickets', ticketApiBody('mary'))
        ->assertCreated()
        ->assertJsonPath('data.order_number', '#001');
});

test('a name already open in the shift gets the next free number, across terminals and cashiers', function () {
    $anna = posUser();
    $ben = posUser();
    posOpenShift($anna);

    Sanctum::actingAs($anna, ['*']);
    $this->postJson('/api/v1/tickets', ticketApiBody('john', 'POS-01'))->assertJsonPath('data.customer_name', 'john');

    Sanctum::actingAs($ben, ['*']);
    $this->postJson('/api/v1/tickets', ticketApiBody('john', 'POS-02'))->assertJsonPath('data.customer_name', 'john2');

    Sanctum::actingAs($anna, ['*']);
    $this->postJson('/api/v1/tickets', ticketApiBody('john', 'POS-01'))->assertJsonPath('data.customer_name', 'john3');
    $this->postJson('/api/v1/tickets', ticketApiBody('mary', 'POS-01'))->assertJsonPath('data.customer_name', 'mary');
});

test('a name frees up once its ticket is no longer open', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $john = posTicket($shift, $cashier, 'john');
    posAddItem($john, posItem('Burger', 100));
    posPay($john->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);
    $john2 = posTicket($shift, $cashier, 'john');
    expect($john2->customer_name)->toBe('john');

    app(TicketService::class)->CancelTicket($john2, $cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson('/api/v1/tickets', ticketApiBody('john'))->assertJsonPath('data.customer_name', 'john');
});

test('a ticket needs a terminal, a customer name and a known order type', function (array $body, array $errors) {
    $cashier = posUser();
    posOpenShift($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson('/api/v1/tickets', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    expect(Ticket::count())->toBe(0);
})->with([
    'nothing' => [[], ['terminal_id', 'customer_name', 'order_type']],
    'unknown order type' => [['terminal_id' => 'POS-01', 'customer_name' => 'john', 'order_type' => 'delivery'], ['order_type']],
    'terminal label too long' => [['terminal_id' => str_repeat('a', 51), 'customer_name' => 'john', 'order_type' => 'dine_in'], ['terminal_id']],
]);

test('the list defaults to the active shift\'s open tickets, oldest first, with live line counts', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $burger = posItem('Burger', 100);
    $john = posTicket($shift, $manager, 'john');
    posAddItem($john, $burger, 2);
    posAddItem($john, posItem('Fries', 50));
    $this->travel(1)->minutes();
    $mary = posTicket($shift, $manager, 'mary');
    $paid = posTicket($shift, $manager, 'pedro');
    posAddItem($paid, $burger);
    posPay($paid->fresh(), $manager, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);
    Sanctum::actingAs($manager, ['*']);

    $open = $this->getJson('/api/v1/tickets')->assertOk()->json('data');
    expect(collect($open)->pluck('id')->all())->toBe([$john->id, $mary->id])
        ->and($open[0]['items_count'])->toBe(2);

    $this->getJson('/api/v1/tickets?status=paid')->assertOk()->assertJsonPath('data.0.id', $paid->id);
});

test('the list is 404 with no active shift', function () {
    Sanctum::actingAs(posUser(), ['*']);

    $this->getJson('/api/v1/tickets')
        ->assertNotFound()
        ->assertJsonPath('message', 'No active shift is open.');
});

test('a fixed discount comes off the subtotal', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100), 3);
    Sanctum::actingAs($cashier, ['*']);

    $data = $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", ['discount_amount' => 50])
        ->assertOk()
        ->json('data');

    expect((float) $data['subtotal'])->toBe(300.0)
        ->and((float) $data['discount_amount'])->toBe(50.0)
        ->and((float) $data['total'])->toBe(250.0);
});

test('a percent discount wins over a fixed amount and rounds to the centavo', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    posAddItem($ticket, posItem('Sisig', 333.33));
    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", ['discount_amount' => 100, 'discount_percent' => 20])
        ->assertOk();

    // 20% of ₱333.33 is ₱66.666 → ₱66.67 off.
    expect((float) $ticket->fresh()->total)->toBe(266.66);
});

test('a discount bigger than the bill makes it free, never negative', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100));
    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", ['discount_amount' => 500])->assertOk();

    expect((float) $ticket->fresh()->total)->toBe(0.0);
});

test('the discount follows later line changes, and sending zeros removes it', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $burger = posItem('Burger', 100);
    posAddItem($ticket, $burger);
    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", ['discount_percent' => 10])->assertOk();
    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $burger->id, 'quantity' => 1])->assertCreated();

    expect((float) $ticket->fresh()->total)->toBe(180.0);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", [])->assertOk();

    expect((float) $ticket->fresh()->total)->toBe(200.0)
        ->and((float) $ticket->fresh()->discount_percent)->toBe(0.0);
});

test('a discount must be zero or more, and a percent at most 100', function (array $body, string $field) {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'negative amount' => [['discount_amount' => -1], 'discount_amount'],
    'negative percent' => [['discount_percent' => -5], 'discount_percent'],
    'percent over 100' => [['discount_percent' => 101], 'discount_percent'],
]);

test('a paid ticket\'s discount cannot change', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100));
    posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);
    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", ['discount_amount' => 50])
        ->assertConflict()
        ->assertJsonPath('message', 'Cannot change discount on a ticket that is not open.');

    expect((float) $ticket->fresh()->total)->toBe(100.0);
});

test('cancelling hands every reserved item back and records who cancelled', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $burger = posItem('Burger', 100, 10);
    $fries = posItem('Fries', 50, 10);
    posAddItem($ticket, $burger, 3);
    posAddItem($ticket, $fries, 2);
    expect($burger->fresh()->reserved_quantity)->toBe(3);
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancelled_by', $cashier->id);

    expect($ticket->fresh()->cancelled_at)->not->toBeNull()
        ->and($burger->fresh()->reserved_quantity)->toBe(0)
        ->and($burger->fresh()->quantity)->toBe(10)
        ->and($fries->fresh()->reserved_quantity)->toBe(0)
        ->and($fries->fresh()->quantity)->toBe(10);
});

test('a voided line is not handed back twice when the ticket is cancelled', function () {
    $cashier = posUser();
    $manager = posUser('manager');
    $manager->update(['passcode' => bcrypt('2468')]);
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $burger = posItem('Burger', 100, 10);
    posAddItem($ticket, $burger, 2);
    $voided = posAddItem($ticket, $burger, 3);
    app(TicketService::class)->VoidItem($voided, $cashier, '2468');
    expect($burger->fresh()->reserved_quantity)->toBe(2);
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/cancel")->assertOk();

    expect($burger->fresh()->reserved_quantity)->toBe(0);
});

test('only an open ticket can be cancelled, and a cancelled one takes no more items', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $paid = posTicket($shift, $cashier, 'john');
    posAddItem($paid, $burger);
    posPay($paid->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);
    $cancelled = posTicket($shift, $cashier, 'mary');
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$paid->id}/cancel")
        ->assertConflict()
        ->assertJsonPath('message', 'Only open tickets can be cancelled.');
    expect($paid->fresh()->status)->toBe('paid');

    $this->postJson("/api/v1/tickets/{$cancelled->id}/cancel")->assertOk();
    $this->postJson("/api/v1/tickets/{$cancelled->id}/cancel")->assertConflict();

    $this->postJson("/api/v1/tickets/{$cancelled->id}/items", ['item_id' => $burger->id, 'quantity' => 1])
        ->assertConflict()
        ->assertJsonPath('message', 'Cannot add items to a ticket that is not open.');
    $this->patchJson("/api/v1/tickets/{$cancelled->id}/discount", ['discount_amount' => 10])->assertConflict();
});
