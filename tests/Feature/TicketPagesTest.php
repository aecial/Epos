<?php

use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use App\Services\ShiftService;
use App\Services\TicketService;

/*
| Back-office Tickets pages (view only): GET /tickets (every ticket from every terminal and
| cashier, newest first, with optional filters) and GET /tickets/{ticket} (lines, payments,
| refunds and merges). Admin/manager only. Money is sent to the page as numbers.
*/

// These assert the Inertia props; the page components themselves are compiled by Vite.
beforeEach(fn () => $this->withoutVite());

/** An open ticket for $customer with $quantity Burgers (₱100 each). */
function burgerTicket($shift, User $cashier, string $customer, int $quantity = 1, string $terminal = 'POS-01'): Ticket
{
    $ticket = posTicket($shift, $cashier, $customer, $terminal);
    posAddItem($ticket, posItem('Burger '.uniqid(), 100), $quantity);

    return $ticket->fresh();
}

test('a manager sees every cashier\'s tickets newest first, with cashier, item count and payment methods', function () {
    $manager = posUser('manager');
    $anna = posUser();
    $ben = posUser();
    $shift = posOpenShift($anna);

    $first = burgerTicket($shift, $anna, 'john', 2);
    posPay($first, $anna, [
        ['payment_method' => 'cash', 'amount' => 150, 'tendered_amount' => 200],
        ['payment_method' => 'gcash', 'amount' => 50, 'payment_reference' => 'GC-1'],
    ]);

    $this->travel(1)->minutes();
    $second = burgerTicket($shift, $ben, 'mary', 1, 'POS-02');

    $this->actingAs($manager)
        ->get('/tickets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('TicketManagementPage')
            ->has('tickets', 2)
            ->where('tickets.0.id', $second->id)
            ->where('tickets.0.status', 'open')
            ->where('tickets.0.created_by', $ben->name)
            ->where('tickets.0.terminal_id', 'POS-02')
            ->where('tickets.0.payment_methods', [])
            ->where('tickets.0.ended_at', null)
            ->where('tickets.1.id', $first->id)
            ->where('tickets.1.status', 'paid')
            ->where('tickets.1.created_by', $anna->name)
            ->where('tickets.1.items_count', 1)
            ->where('tickets.1.total', 200)
            ->where('tickets.1.payment_methods', ['cash', 'gcash'])
            ->where('pagination.total', 2)
        );
});

test('the list paginates 20 per page', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    foreach (range(1, 21) as $number) {
        posTicket($shift, $cashier, "customer {$number}");
    }

    $this->actingAs($manager)
        ->get('/tickets?page=2')
        ->assertInertia(fn ($page) => $page
            ->has('tickets', 1)
            ->where('pagination.current_page', 2)
            ->where('pagination.last_page', 2)
            ->where('pagination.total', 21)
        );
});

test('the list filters by status, shift, payment method, opened date and search (order number, customer or receipt number)', function () {
    $manager = posUser('manager');
    $cashier = posUser();

    $this->travelTo('2026-10-01 10:00:00');
    $oldShift = posOpenShift($cashier);
    $oldPaid = burgerTicket($oldShift, $cashier, 'john');
    posPay($oldPaid, $cashier, [['payment_method' => 'gcash', 'amount' => 100, 'payment_reference' => 'GC-1']]);
    app(ShiftService::class)->CloseShift($oldShift, $cashier, 1000);

    $this->travelTo('2026-10-02 10:00:00');
    $shift = posOpenShift($cashier);
    $cashPaid = burgerTicket($shift, $cashier, 'maria');
    posPay($cashPaid, $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);
    $open = burgerTicket($shift, $cashier, 'pedro');

    $idsFor = fn (string $query): array => $this->actingAs($manager)->get("/tickets{$query}")
        ->assertOk()
        ->viewData('page')['props']['tickets'];

    expect(collect($idsFor('?status=paid'))->pluck('id')->all())->toEqualCanonicalizing([$oldPaid->id, $cashPaid->id])
        ->and(collect($idsFor("?shift_id={$oldShift->id}"))->pluck('id')->all())->toBe([$oldPaid->id])
        ->and(collect($idsFor('?payment_method=gcash'))->pluck('id')->all())->toBe([$oldPaid->id])
        ->and(collect($idsFor('?payment_method=cash'))->pluck('id')->all())->toBe([$cashPaid->id])
        ->and(collect($idsFor('?date_from=2026-10-02'))->pluck('id')->all())->toEqualCanonicalizing([$cashPaid->id, $open->id])
        ->and(collect($idsFor('?date_to=2026-10-01'))->pluck('id')->all())->toBe([$oldPaid->id])
        ->and(collect($idsFor('?search=pedr'))->pluck('id')->all())->toBe([$open->id])
        ->and(collect($idsFor('?search='.$cashPaid->receipts()->value('receipt_number')))->pluck('id')->all())->toBe([$cashPaid->id])
        ->and(collect($idsFor('?search='.urlencode($cashPaid->order_number).'&shift_id='.$shift->id))->pluck('id')->all())->toBe([$cashPaid->id])
        ->and(collect($idsFor('?status=open&payment_method=cash'))->all())->toBe([]);

    $this->actingAs($manager)
        ->get('/tickets?status=paid&search=')
        ->assertInertia(fn ($page) => $page->where('filters', ['status' => 'paid']));
});

test('an invalid filter is rejected', function () {
    $this->actingAs(posUser('manager'))
        ->from('/tickets')
        ->get('/tickets?status=refunded')
        ->assertRedirect('/tickets')
        ->assertSessionHasErrors('status');
});

test('a manager sees a paid ticket\'s lines, split payments with receipt numbers, a voided line and a refund', function () {
    $manager = posUser('manager');
    $manager->update(['passcode' => '2468']);
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $ticket = posTicket($shift, $cashier, 'john');
    $burger = posAddItem($ticket, posItem('Burger', 100), 2, 'no onions');
    $fries = posAddItem($ticket, posItem('Fries', 50));
    app(TicketService::class)->VoidItem($fries, $cashier, '2468');
    app(TicketService::class)->SetDiscount($ticket->fresh(), 0, 10);

    $paid = posPay($ticket->fresh(), $cashier, [
        ['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 150],
        ['payment_method' => 'gcash', 'amount' => 80, 'payment_reference' => 'GC-77'],
    ]);
    $cashCharge = $paid->charges()->where('payment_method', 'cash')->first();
    $refund = app(RefundService::class)->RequestRefund($paid, $cashCharge, $cashier, [
        ['ticket_item_id' => $burger->id, 'quantity' => 1, 'amount' => 90],
    ], 'Cold');
    app(RefundService::class)->ApproveRefund($refund, $cashier, '2468');

    $this->actingAs($manager)
        ->get("/tickets/{$ticket->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('TicketDetailPage')
            ->where('ticket.id', $ticket->id)
            ->where('ticket.status', 'paid')
            ->where('ticket.created_by', $cashier->name)
            ->where('ticket.subtotal', 200)
            ->where('ticket.discount_percent', 10)
            ->where('ticket.total', 180)
            ->where('ticket.merged_into', null)
            ->where('ticket.merged_from', [])
            ->has('items', 2)
            ->where('items.0.item_name', 'Burger')
            ->where('items.0.quantity', 2)
            ->where('items.0.unit_price', 100)
            ->where('items.0.line_total', 200)
            ->where('items.0.notes', 'no onions')
            ->where('items.0.voided_at', null)
            ->where('items.1.item_name', 'Fries')
            ->where('items.1.voided_by', $manager->name)
            ->where('items.1.voided_requested_by', $cashier->name)
            ->missing('items.0.item_cost_price')
            ->has('charges', 2)
            ->where('charges.0.payment_method', 'cash')
            ->where('charges.0.amount', 100)
            ->where('charges.0.tendered_amount', 150)
            ->where('charges.0.change_due', 50)
            ->where('charges.0.created_by', $cashier->name)
            ->where('charges.0.receipt_number', fn (string $number) => str_starts_with($number, 'REC-'))
            ->where('charges.0.receipt_id', $paid->receipts()->where('payment_method', 'cash')->value('id'))
            ->where('charges.1.payment_method', 'gcash')
            ->where('charges.1.payment_reference', 'GC-77')
            ->has('refunds', 1)
            ->where('refunds.0.status', 'approved')
            ->where('refunds.0.amount', 90)
            ->where('refunds.0.payment_method', 'cash')
            ->where('refunds.0.reason', 'Cold')
            ->where('refunds.0.approved_by', $manager->name)
            ->where('refunds.0.items', [['item_name' => 'Burger', 'quantity' => 1, 'amount' => 90]])
        );
});

test('a merged source links to its target, and the target lists its sources and labels the moved lines', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $target = burgerTicket($shift, $cashier, 'john');
    $source = burgerTicket($shift, $cashier, 'mary');
    app(TicketService::class)->MergeTickets($target, [$source->id], $cashier);

    $this->actingAs($manager)
        ->get("/tickets/{$source->id}")
        ->assertInertia(fn ($page) => $page
            ->where('ticket.status', 'merged')
            ->where('ticket.merged_by', $cashier->name)
            ->where('ticket.ended_at', fn ($value) => $value !== null)
            ->where('ticket.merged_into', ['id' => $target->id, 'order_number' => $target->order_number, 'customer_name' => 'john'])
            ->has('items', 0)
            ->where('ticket.total', 0)
        );

    $this->actingAs($manager)
        ->get("/tickets/{$target->id}")
        ->assertInertia(fn ($page) => $page
            ->where('ticket.merged_from', [['id' => $source->id, 'order_number' => $source->order_number, 'customer_name' => 'mary']])
            ->has('items', 2)
            ->where('items.0.merged_from_order_number', null)
            ->where('items.1.merged_from_order_number', $source->order_number)
            ->where('ticket.total', 200)
        );
});

test('a cancelled ticket shows who cancelled it and when', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $ticket = burgerTicket(posOpenShift($cashier), $cashier, 'john');
    app(TicketService::class)->CancelTicket($ticket, $cashier);

    $this->actingAs($manager)
        ->get("/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->where('ticket.status', 'cancelled')
            ->where('ticket.cancelled_by', $cashier->name)
            ->where('ticket.ended_at', fn ($value) => $value !== null)
            ->has('charges', 0)
        );
});

test('a cashier is signed out of both pages, even for a ticket they opened; a guest is sent to login', function () {
    $cashier = posUser();
    $ticket = burgerTicket(posOpenShift($cashier), $cashier, 'john');

    $this->actingAs($cashier)->get('/tickets')->assertRedirect('/login');
    $this->actingAs($cashier)->get("/tickets/{$ticket->id}")->assertRedirect('/login');

    auth()->logout();
    $this->get('/tickets')->assertRedirect('/login');
    $this->get("/tickets/{$ticket->id}")->assertRedirect('/login');
});
