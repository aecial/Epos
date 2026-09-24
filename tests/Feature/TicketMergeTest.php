<?php

use App\Models\Shift;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

test('merging moves every line onto the target and leaves the sources as zeroed history', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $rice = posItem('Rice', 50);

    $target = posTicket($shift, $cashier, 'john');
    $source = posTicket($shift, $cashier, 'maria');
    posAddItem($target, $burger, 2);
    $riceLine = posAddItem($source, $rice, 2);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => [$source->id]])
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.merged_tickets.0.order_number', $source->order_number);

    $target->refresh();
    $source->refresh();

    expect((float) $target->subtotal)->toBe(300.0)
        ->and((float) $target->total)->toBe(300.0)
        ->and($source->status)->toBe('merged')
        ->and($source->merged_into_ticket_id)->toBe($target->id)
        ->and($source->merged_by)->toBe($cashier->id)
        ->and($source->merged_at)->not->toBeNull()
        ->and((float) $source->total)->toBe(0.0)
        // The line moved to the target but remembers it came from maria's ticket.
        ->and($riceLine->fresh()->ticket_id)->toBe($target->id)
        ->and($riceLine->fresh()->merged_from_ticket_id)->toBe($source->id);
});

test('merging does not touch inventory because reservations belong to items, not tickets', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100, quantity: 10);

    $target = posTicket($shift, $cashier, 'john');
    $source = posTicket($shift, $cashier, 'maria');
    posAddItem($target, $burger, 2);
    posAddItem($source, $burger, 3);

    expect($burger->fresh()->reserved_quantity)->toBe(5);

    app(TicketService::class)->MergeTickets($target, [$source->id], $cashier);

    expect($burger->fresh()->quantity)->toBe(10)
        ->and($burger->fresh()->reserved_quantity)->toBe(5);
});

test('discounts from every ticket are combined into one fixed amount', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $rice = posItem('Rice', 50);
    $tickets = app(TicketService::class);

    $target = posTicket($shift, $cashier, 'john');
    $source = posTicket($shift, $cashier, 'maria');
    posAddItem($target, $burger, 3);              // 300
    $tickets->SetDiscount($target, 0, 10);        // 10% => 30 off
    posAddItem($source, $rice, 2);                // 100
    $tickets->SetDiscount($source, 20, 0);        // fixed 20 off

    $merged = $tickets->MergeTickets($target, [$source->id], $cashier);

    expect((float) $merged->subtotal)->toBe(400.0)
        ->and((float) $merged->discount_amount)->toBe(50.0)
        ->and((float) $merged->discount_percent)->toBe(0.0)
        ->and((float) $merged->total)->toBe(350.0);
});

test('ticket notes from merged tickets are kept, labelled by order number', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $target = posTicket($shift, $cashier, 'john');
    $source = posTicket($shift, $cashier, 'maria');
    $target->update(['notes' => 'birthday table']);
    $source->update(['notes' => 'no peanuts']);

    $merged = app(TicketService::class)->MergeTickets($target, [$source->id], $cashier);

    expect($merged->notes)->toBe("birthday table\n{$source->order_number}: no peanuts");
});

test('only open tickets can be merged and a rejected merge changes nothing', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $rice = posItem('Rice', 50);

    $target = posTicket($shift, $cashier, 'john');
    $cancelled = posTicket($shift, $cashier, 'maria');
    posAddItem($target, $rice, 1);
    posAddItem($cancelled, $rice, 1);
    app(TicketService::class)->CancelTicket($cancelled, $cashier);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => [$cancelled->id]])
        ->assertStatus(409);

    expect($target->fresh()->items()->count())->toBe(1)
        ->and($cancelled->fresh()->status)->toBe('cancelled');
});

test('tickets from different shifts cannot be merged', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $oldShift = Shift::create([
        'opened_by' => $cashier->id,
        'status' => 'closed',
        'starting_cash' => 0,
        'opened_at' => now()->subDay(),
        'closed_at' => now()->subDay(),
    ]);

    $target = posTicket($shift, $cashier, 'john');
    $foreign = Ticket::create([
        'shift_id' => $oldShift->id,
        'created_by' => $cashier->id,
        'terminal_id' => 'POS-01',
        'customer_name' => 'ghost',
        'order_number' => '#001',
        'order_type' => 'dine_in',
        'status' => 'open',
    ]);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => [$foreign->id]])
        ->assertStatus(409);

    expect($foreign->fresh()->status)->toBe('open');
});

test('a ticket cannot be merged into itself', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $target = posTicket($shift, $cashier, 'john');

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => [$target->id]])
        ->assertStatus(409);
});

test('merge requires at least one valid ticket id', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $target = posTicket($shift, $cashier, 'john');

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['merge_from_ticket_ids']);

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => [999999]])
        ->assertUnprocessable();
});

test('merging requires authentication', function () {
    $this->postJson('/api/v1/tickets/1/merge', ['merge_from_ticket_ids' => [2]])
        ->assertUnauthorized();
});

test('chained merges keep the full merged list and each line\'s original ticket', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $rice = posItem('Rice', 50);
    $tickets = app(TicketService::class);

    $a = posTicket($shift, $cashier, 'anna');
    $b = posTicket($shift, $cashier, 'ben');
    $c = posTicket($shift, $cashier, 'cara');
    $lineA = posAddItem($a, $rice, 1);
    $lineB = posAddItem($b, $rice, 1);
    posAddItem($c, $rice, 1);

    $tickets->MergeTickets($b, [$a->id], $cashier);   // A -> B
    $merged = $tickets->MergeTickets($c, [$b->id], $cashier);   // B (which holds A's line) -> C

    expect($merged->mergedTickets->pluck('id')->sort()->values()->all())
        ->toBe([$a->id, $b->id])
        // A's line is still attributed to A, not overwritten to B.
        ->and($lineA->fresh()->merged_from_ticket_id)->toBe($a->id)
        ->and($lineB->fresh()->merged_from_ticket_id)->toBe($b->id)
        ->and($a->fresh()->merged_into_ticket_id)->toBe($c->id)
        ->and((float) $merged->total)->toBe(150.0);
});

test('a merged ticket is paid once, prints one receipt with every order number, and does not block closing the shift', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $rice = posItem('Rice', 50);

    $target = posTicket($shift, $cashier, 'john');
    $source = posTicket($shift, $cashier, 'maria');
    posAddItem($target, $burger, 2);
    posAddItem($source, $rice, 2);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => [$source->id]])->assertOk();

    // The merged source has left the open list; only the target remains.
    $this->getJson('/api/v1/tickets?terminal_id=POS-01')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);

    $this->postJson("/api/v1/tickets/{$target->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 300, 'tendered_amount' => 300]],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data.charges')
        ->assertJsonPath('data.charges.0.receipt.payload.order.order_number', $target->order_number)
        ->assertJsonPath('data.charges.0.receipt.payload.order.merged_from.0.order_number', $source->order_number)
        ->assertJsonPath('data.charges.0.receipt.payload.order.merged_from.0.customer_name', 'maria')
        ->assertJsonCount(2, 'data.charges.0.receipt.payload.items');

    expect(TicketItem::query()->where('ticket_id', $target->id)->count())->toBe(2);

    // Merged + paid tickets are both "not open", so the shift can close.
    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1300])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.total_revenue', 300)
        ->assertJsonPath('data.discrepancy', 0);
});
