<?php

use App\Events\Kds\ItemCompleted;
use App\Events\Kds\TicketUpdated;
use App\Models\Category;
use App\Models\Item;
use App\Services\KdsService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Event;

/*
| Back-office Kitchen Orders page: GET /kitchen-orders shows the same cards as the KDS feed
| (GET /api/v1/kds/orders), and a manager/admin can bump from it - one line
| (PATCH /kitchen-orders/items/{ticketItem}/complete) or the whole order
| (PATCH /kitchen-orders/{ticket}/complete) - through the same TicketService methods as the tablet.
*/

// These assert the Inertia props; the page component itself is compiled by Vite.
beforeEach(fn () => $this->withoutVite());

function kitchenFeeItem(): Item
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

test('a manager sees the same cards as the kds feed, oldest first, without paid tickets or fee lines', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $first = posTicket($shift, $cashier, 'john');
    posAddItem($first, posItem('Fried Itik', 300), 1, 'extra crispy');
    app(TicketService::class)->AddItem($first, kitchenFeeItem(), 1, [], unitPrice: 50);

    $this->travel(1)->minutes();
    $second = posTicket($shift, $cashier, 'mary');
    posAddItem($second, posItem('Rice', 20), 2);

    $this->travel(1)->minutes();
    $paid = posTicket($shift, $cashier, 'pedro');
    posAddItem($paid, posItem('Softdrinks', 30));
    posPay($paid->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 30, 'tendered_amount' => 30]]);

    $feed = json_decode(json_encode(app(KdsService::class)->GetOpenOrders()), true);

    $this->actingAs($manager)
        ->get('/kitchen-orders')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('KitchenOrdersPage')
            ->where('orders', $feed)
            ->has('orders', 2)
            ->where('orders.0.ticket_id', $first->id)
            ->has('orders.0.items', 1)
            ->where('orders.0.items.0.item_name', 'Fried Itik')
            ->where('orders.0.items.0.notes', 'extra crispy')
            ->where('orders.1.ticket_id', $second->id)
            ->where('orders.1.items.0.quantity', 2)
        );
});

test('bumping one item drops just that line from the card and broadcasts like the tablet', function () {
    Event::fake([ItemCompleted::class]);
    $manager = posUser('manager');
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $itik = posAddItem($ticket, posItem('Fried Itik', 300));
    posAddItem($ticket, posItem('Rice', 20), 2);

    $this->actingAs($manager)
        ->from('/kitchen-orders')
        ->patch("/kitchen-orders/items/{$itik->id}/complete")
        ->assertRedirect('/kitchen-orders');

    expect($itik->fresh()->completed_at)->not->toBeNull();
    Event::assertDispatched(ItemCompleted::class, 1);

    $this->actingAs($manager)
        ->get('/kitchen-orders')
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->has('orders.0.items', 1)
            ->where('orders.0.items.0.item_name', 'Rice')
        );
});

test('bumping the whole order clears the card, and a later add-on shows alone', function () {
    Event::fake([TicketUpdated::class]);
    $manager = posUser('manager');
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    posAddItem($ticket, posItem('Fried Itik', 300));
    posAddItem($ticket, posItem('Rice', 20), 2);

    $this->actingAs($manager)
        ->from('/kitchen-orders')
        ->patch("/kitchen-orders/{$ticket->id}/complete")
        ->assertRedirect('/kitchen-orders');

    Event::assertDispatched(TicketUpdated::class);
    $this->actingAs($manager)->get('/kitchen-orders')->assertInertia(fn ($page) => $page->has('orders', 0));

    posAddItem($ticket->fresh(), posItem('Softdrinks', 30));

    $this->actingAs($manager)
        ->get('/kitchen-orders')
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->has('orders.0.items', 1)
            ->where('orders.0.items.0.item_name', 'Softdrinks')
        );
});

test('bumping an order that was paid in the meantime flashes an error instead of failing', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $line = posAddItem($ticket, posItem('Rice', 20));
    posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 20, 'tendered_amount' => 20]]);

    $this->actingAs($manager)
        ->from('/kitchen-orders')
        ->patch("/kitchen-orders/{$ticket->id}/complete")
        ->assertRedirect('/kitchen-orders')
        ->assertSessionHas('error', 'Cannot complete items on a ticket that is not open.');

    $this->actingAs($manager)
        ->from('/kitchen-orders')
        ->patch("/kitchen-orders/items/{$line->id}/complete")
        ->assertRedirect('/kitchen-orders')
        ->assertSessionHas('error', 'Cannot change item completion state on a ticket that is not open.');

    expect($line->fresh()->completed_at)->toBeNull();
});

test('a cashier can neither see nor bump from the page; a guest is sent to login', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $line = posAddItem($ticket, posItem('Rice', 20));

    $this->actingAs($cashier)->get('/kitchen-orders')->assertRedirect('/login');
    $this->actingAs($cashier)->patch("/kitchen-orders/items/{$line->id}/complete")->assertRedirect('/login');
    $this->actingAs($cashier)->patch("/kitchen-orders/{$ticket->id}/complete")->assertRedirect('/login');
    expect($line->fresh()->completed_at)->toBeNull();

    auth()->logout();
    $this->get('/kitchen-orders')->assertRedirect('/login');
    $this->patch("/kitchen-orders/{$ticket->id}/complete")->assertRedirect('/login');
});
