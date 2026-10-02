<?php

use App\Models\Category;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

/*
| GET /api/v1/kds/orders - the kitchen-facing feed. Strictly created_at ASC across all
| terminals (CLAUDE.md), excludes paid/cancelled/merged tickets and voided or `fee` lines,
| and exposes no prices or terminal info (ENHANCED_SPEC.md §8).
*/

function kdsFeeItem(): Item
{
    $category = Category::create(['name' => 'Fees', 'type' => 'special', 'status' => 'active', 'is_visible_to_pos' => true]);

    return Item::create([
        'category_id' => $category->id,
        'name' => 'Delivery Fee',
        'base_price' => 0,
        'cost_price' => 0,
        'inventory_type' => 'none',
        'entry_mode' => 'price',
        'status' => 'available',
    ]);
}

test('the kds orders endpoint requires authentication', function () {
    $this->getJson('/api/v1/kds/orders')->assertUnauthorized();
});

test('item and custom lines are shown; fee and voided lines are hidden', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');

    posAddItem($ticket, $item, 1);

    $fee = kdsFeeItem();
    app(TicketService::class)->AddItem($ticket, $fee, 1, [], unitPrice: 50);

    $customItem = Item::create([
        'category_id' => $fee->category_id,
        'name' => 'Custom',
        'base_price' => 0,
        'cost_price' => 0,
        'inventory_type' => 'none',
        'entry_mode' => 'name_price',
        'status' => 'available',
    ]);
    app(TicketService::class)->AddItem($ticket, $customItem, 1, [], unitPrice: 75, customName: 'Birthday Candle');

    posUser('manager');
    $toVoid = posAddItem($ticket, $item, 1);
    app(TicketService::class)->VoidItem($toVoid, $cashier, '1234');

    Sanctum::actingAs($cashier, ['*']);

    $response = $this->getJson('/api/v1/kds/orders')->assertOk();

    $items = collect($response->json('data.0.items'));
    expect($items)->toHaveCount(2);
    expect($items->pluck('item_name')->all())->toBe(['Burger', 'Birthday Candle']);
});

test('tickets are ordered strictly by created_at ascending, items within a card too', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);

    $ticketA = posTicket($shift, $cashier, 'alice');
    $ticketA->forceFill(['created_at' => now()->subMinutes(5)])->save();
    $ticketB = posTicket($shift, $cashier, 'bob');
    $ticketB->forceFill(['created_at' => now()->subMinutes(2)])->save();
    posAddItem($ticketB, $item, 1);

    $lineLater = posAddItem($ticketA, $item, 1);
    $lineLater->forceFill(['created_at' => now()->subMinutes(1)])->save();
    $lineEarlier = posAddItem($ticketA, $item, 1);
    $lineEarlier->forceFill(['created_at' => now()->subMinutes(4)])->save();

    Sanctum::actingAs($cashier, ['*']);

    $response = $this->getJson('/api/v1/kds/orders')->assertOk();

    expect($response->json('data.0.customer_name'))->toBe('alice');
    expect($response->json('data.1.customer_name'))->toBe('bob');

    $items = collect($response->json('data.0.items'));
    expect($items->pluck('ticket_item_id')->all())->toBe([$lineEarlier->id, $lineLater->id]);
});

test('paid, cancelled and merged tickets are excluded from the feed', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);

    $paid = posTicket($shift, $cashier, 'paid-ticket');
    posAddItem($paid, $item, 1);
    posPay($paid, $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    $cancelled = posTicket($shift, $cashier, 'cancelled-ticket');
    app(TicketService::class)->CancelTicket($cancelled, $cashier);

    $target = posTicket($shift, $cashier, 'target-ticket');
    posAddItem($target, $item, 1);
    $source = posTicket($shift, $cashier, 'source-ticket');
    app(TicketService::class)->MergeTickets($target, [$source->id], $cashier);

    $open = posTicket($shift, $cashier, 'open-ticket');
    posAddItem($open, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    $response = $this->getJson('/api/v1/kds/orders')->assertOk();

    $names = collect($response->json('data'))->pluck('customer_name')->all();
    expect($names)->toContain('open-ticket', 'target-ticket');
    expect($names)->not->toContain('paid-ticket', 'cancelled-ticket', 'source-ticket');
});

test('no price fields or terminal info appear anywhere in the payload', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');

    $group = ModifierGroup::create(['name' => 'Extras']);
    $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Cheese', 'status' => 'active']);
    $item->modifiers()->attach($modifier->id, ['status' => 'active', 'display_order' => 1]);

    app(TicketService::class)->AddItem($ticket, $item->fresh(), 1, [$modifier->id]);

    Sanctum::actingAs($cashier, ['*']);

    $raw = $this->getJson('/api/v1/kds/orders')->assertOk()->getContent();

    expect($raw)->not->toContain('unit_price')
        ->not->toContain('line_total')
        ->not->toContain('item_cost_price')
        ->not->toContain('terminal_id')
        ->not->toContain('price');
});

test('completed items drop off the feed entirely, pending ones remain', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');

    $done = posAddItem($ticket, $item, 1);
    $done->update(['completed_at' => now()]);
    $pending = posAddItem($ticket, $item, 1);

    Sanctum::actingAs($cashier, ['*']);

    $response = $this->getJson('/api/v1/kds/orders')->assertOk();

    $items = collect($response->json('data.0.items'));
    expect($items->pluck('ticket_item_id')->all())->toBe([$pending->id]);
});

test('a ticket with every item completed disappears from the feed until something new is added', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');

    $served = posAddItem($ticket, $item, 1);
    $served->update(['completed_at' => now()]);

    Sanctum::actingAs($cashier, ['*']);

    $this->getJson('/api/v1/kds/orders')->assertOk()->assertJsonCount(0, 'data');

    $addOn = app(TicketService::class)->AddItem($ticket, $item, 1);

    $response = $this->getJson('/api/v1/kds/orders')->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $items = collect($response->json('data.0.items'));
    expect($items->pluck('ticket_item_id')->all())->toBe([$addOn->id]);
});

test('bumping a whole ticket then adding more shows only the new lines, not what was already served', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $friedItik = posItem('Fried Itik', 150);
    $rice = posItem('Rice', 20);
    $softdrink = posItem('Softdrink', 30);
    $ticket = posTicket($shift, $cashier, 'john');

    posAddItem($ticket, $friedItik, 1);
    posAddItem($ticket, $rice, 2);

    Sanctum::actingAs($cashier, ['*']);

    $this->patchJson("/api/v1/kds/orders/{$ticket->id}/complete")->assertOk();
    $this->getJson('/api/v1/kds/orders')->assertOk()->assertJsonCount(0, 'data');

    $newRice = app(TicketService::class)->AddItem($ticket, $rice, 2);
    $newSoftdrink = app(TicketService::class)->AddItem($ticket, $softdrink, 1);

    $response = $this->getJson('/api/v1/kds/orders')->assertOk();
    expect($response->json('data'))->toHaveCount(1);

    $items = collect($response->json('data.0.items'));
    expect($items->pluck('ticket_item_id')->all())->toBe([$newRice->id, $newSoftdrink->id]);
    expect($items->pluck('item_name')->all())->toBe(['Rice', 'Softdrink']);
});

test('adding an item while others on the ticket are still pending appends to the same card, not a new one', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $friedItik = posItem('Fried Itik', 150);
    $rice = posItem('Rice', 20);
    $ticket = posTicket($shift, $cashier, 'john');

    $existing = posAddItem($ticket, $friedItik, 1);

    Sanctum::actingAs($cashier, ['*']);

    $this->getJson('/api/v1/kds/orders')->assertOk()->assertJsonCount(1, 'data');

    $newItem = app(TicketService::class)->AddItem($ticket, $rice, 2);

    $response = $this->getJson('/api/v1/kds/orders')->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.ticket_id'))->toBe($ticket->id);

    $items = collect($response->json('data.0.items'));
    expect($items->pluck('ticket_item_id')->all())->toBe([$existing->id, $newItem->id]);
});
