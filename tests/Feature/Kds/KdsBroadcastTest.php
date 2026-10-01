<?php

use App\Events\Kds\ItemCompleted;
use App\Events\Kds\ItemUncompleted;
use App\Events\Kds\TicketCancelled;
use App\Events\Kds\TicketCreated;
use App\Events\Kds\TicketMerged;
use App\Events\Kds\TicketPaid;
use App\Events\Kds\TicketUpdated;
use App\Services\TicketService;
use Illuminate\Support\Facades\Event;

/*
| broadcast() ultimately dispatches through the normal event dispatcher (PendingBroadcast's
| destructor calls app('events')->dispatch()), so Event::fake() intercepts it exactly like any
| other event - no Broadcast::fake() needed. Every TicketService/PaymentService mutation fires
| its broadcast AFTER the DB transaction returns, so a rollback must never dispatch one either.
*/

beforeEach(function () {
    Event::fake([
        TicketCreated::class,
        TicketUpdated::class,
        TicketPaid::class,
        TicketCancelled::class,
        TicketMerged::class,
        ItemCompleted::class,
        ItemUncompleted::class,
    ]);
});

test('creating a ticket dispatches ticket.created', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    posTicket($shift, $cashier, 'john');

    Event::assertDispatched(TicketCreated::class);
});

test('adding, voiding and changing quantity all dispatch ticket.updated', function () {
    $cashier = posUser();
    posUser('manager');
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');

    $line = posAddItem($ticket, $item, 2);
    Event::assertDispatched(TicketUpdated::class, 1);

    app(TicketService::class)->UpdateItemQuantity($line, 1);
    Event::assertDispatched(TicketUpdated::class, 2);

    app(TicketService::class)->VoidItem($line, $cashier, '1234');
    Event::assertDispatched(TicketUpdated::class, 3);
});

test('paying a ticket dispatches ticket.paid', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    Event::assertDispatched(TicketPaid::class);
});

test('cancelling a ticket dispatches ticket.cancelled', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');

    app(TicketService::class)->CancelTicket($ticket, $cashier);

    Event::assertDispatched(TicketCancelled::class);
});

test('merging tickets dispatches ticket.merged and ticket.updated', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $target = posTicket($shift, $cashier, 'john');
    $source = posTicket($shift, $cashier, 'jane');

    app(TicketService::class)->MergeTickets($target, [$source->id], $cashier);

    Event::assertDispatched(TicketMerged::class, function (TicketMerged $event) use ($source) {
        return $event->removedTicketIds === [$source->id]
            && $event->removedOrderNumbers === [$source->order_number];
    });
    Event::assertDispatched(TicketUpdated::class);
});

test('merging multiple sources reports every removed ticket id and order number', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $target = posTicket($shift, $cashier, 'john');
    $sourceA = posTicket($shift, $cashier, 'jane');
    $sourceB = posTicket($shift, $cashier, 'mike');

    app(TicketService::class)->MergeTickets($target, [$sourceA->id, $sourceB->id], $cashier);

    Event::assertDispatched(TicketMerged::class, function (TicketMerged $event) use ($sourceA, $sourceB) {
        return $event->removedTicketIds === [$sourceA->id, $sourceB->id]
            && $event->removedOrderNumbers === [$sourceA->order_number, $sourceB->order_number];
    });
});

test('toggling item completion dispatches item.completed and item.uncompleted', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);

    app(TicketService::class)->SetItemCompletion($line, true);
    Event::assertDispatched(ItemCompleted::class);
    Event::assertNotDispatched(ItemUncompleted::class);

    app(TicketService::class)->SetItemCompletion($line, false);
    Event::assertDispatched(ItemUncompleted::class);
});

test('changing the discount dispatches no kds event', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    Event::fake([TicketCreated::class, TicketUpdated::class, TicketPaid::class, TicketCancelled::class, TicketMerged::class, ItemCompleted::class, ItemUncompleted::class]);

    app(TicketService::class)->SetDiscount($ticket, discountAmount: 10);

    Event::assertNotDispatched(TicketUpdated::class);
});

test('a rolled-back mutation never dispatches a broadcast', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);
    posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    Event::fake([TicketCreated::class, TicketUpdated::class, TicketPaid::class, TicketCancelled::class, TicketMerged::class, ItemCompleted::class, ItemUncompleted::class]);

    // Ticket is already paid; adding an item must fail before any broadcast fires.
    expect(fn () => app(TicketService::class)->AddItem($ticket, $item, 1))
        ->toThrow(InvalidArgumentException::class);

    Event::assertNotDispatched(TicketUpdated::class);
});
