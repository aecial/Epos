<?php

use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use App\Services\ShiftService;
use App\Services\ShiftTransactionService;
use App\Services\TicketService;

/*
| Back-office Shifts pages (view only): GET /shifts (history, newest first) and
| GET /shifts/{shift} (full cash breakdown / close report). Admin/manager only. An open shift
| shows live totals; a closed shift shows the snapshot written when it closed. Money is sent to
| the page as numbers, never decimal strings.
*/

// These assert the Inertia props; the page components themselves are compiled by Vite.
beforeEach(fn () => $this->withoutVite());

/** A paid cash ticket of $quantity Burgers (₱100 each) on $shift. */
function paidBurgerTicket(Shift $shift, User $cashier, int $quantity = 1): Ticket
{
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, posItem('Burger '.uniqid(), 100), $quantity);

    return posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100 * $quantity, 'tendered_amount' => 100 * $quantity]]);
}

test('a manager sees every shift newest first, with snapshot totals for closed shifts and live totals for the open one', function () {
    $manager = posUser('manager');
    $cashier = posUser();

    $closed = posOpenShift($cashier);
    paidBurgerTicket($closed, $cashier);
    app(ShiftService::class)->CloseShift($closed, $cashier, 1090);

    $this->travel(1)->hours();
    $open = posOpenShift($cashier);
    paidBurgerTicket($open, $cashier, 3);

    $this->actingAs($manager)
        ->get('/shifts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ShiftManagementPage')
            ->has('shifts', 2)
            ->where('shifts.0.id', $open->id)
            ->where('shifts.0.status', 'open')
            ->where('shifts.0.total_cash', 300)
            ->where('shifts.0.expected_cash', 1300)
            ->where('shifts.0.closing_cash', null)
            ->where('shifts.0.discrepancy', null)
            ->where('shifts.1.id', $closed->id)
            ->where('shifts.1.status', 'closed')
            ->where('shifts.1.opened_by', $cashier->name)
            ->where('shifts.1.closed_by', $cashier->name)
            ->where('shifts.1.starting_cash', 1000)
            ->where('shifts.1.total_revenue', 100)
            ->where('shifts.1.expected_cash', 1100)
            ->where('shifts.1.closing_cash', 1090)
            ->where('shifts.1.discrepancy', -10)
            ->where('pagination.total', 2)
        );
});

test('the shift list is paginated 20 per page', function () {
    $manager = posUser('manager');

    foreach (range(1, 25) as $day) {
        Shift::create([
            'opened_by' => $manager->id,
            'closed_by' => $manager->id,
            'status' => 'closed',
            'starting_cash' => 1000,
            'opened_at' => now()->subDays($day),
            'closed_at' => now()->subDays($day)->addHours(8),
        ]);
    }

    $this->actingAs($manager)->get('/shifts')
        ->assertInertia(fn ($page) => $page
            ->has('shifts', 20)
            ->where('pagination.current_page', 1)
            ->where('pagination.last_page', 2)
            ->where('pagination.total', 25)
        );

    $this->actingAs($manager)->get('/shifts?page=2')
        ->assertInertia(fn ($page) => $page->has('shifts', 5)->where('pagination.current_page', 2));
});

test('a closed shift\'s detail shows the closing snapshot, its cash movements, refunds and ticket counts', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $transactions = app(ShiftTransactionService::class);
    $transactions->AddTransaction($shift, $manager, 'addition', 200, 'Change fund top-up');
    $transactions->AddTransaction($shift, $manager, 'expense', 50, 'Ice');
    $mistake = $transactions->AddTransaction($shift, $manager, 'expense', 999, 'Typo');
    $transactions->DeleteTransaction($mistake, $manager);

    $paid = paidBurgerTicket($shift, $cashier, 2);
    $line = $paid->items()->first();
    $charge = $paid->charges()->first();

    $refunds = app(RefundService::class);
    $refund = $refunds->RequestRefund($paid, $charge, $cashier, [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 40]], 'Cold');
    $refunds->ApproveRefund($refund, $cashier, '1234');

    $cancelled = posTicket($shift, $cashier, 'maria');
    app(TicketService::class)->CancelTicket($cancelled, $cashier);

    // expected = 1000 starting + 200 cash sales + 200 additions − 50 expenses − 40 cash refunds = 1310
    app(ShiftService::class)->CloseShift($shift, $cashier, 1300);

    // A refund approved AFTER the close is listed, but must not change the closing snapshot.
    $late = $refunds->RequestRefund($paid, $charge, $cashier, [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 30]], 'Late');
    $refunds->ApproveRefund($late, $cashier, '1234');

    $this->actingAs($manager)
        ->get("/shifts/{$shift->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ShiftDetailPage')
            ->where('shift.id', $shift->id)
            ->where('shift.status', 'closed')
            ->where('shift.starting_cash', 1000)
            ->where('shift.total_cash', 200)
            ->where('shift.total_additions', 200)
            ->where('shift.total_expenses', 50)
            ->where('shift.total_refunds', 40)
            ->where('shift.total_cash_refunds', 40)
            ->where('shift.expected_cash', 1310)
            ->where('shift.closing_cash', 1300)
            ->where('shift.discrepancy', -10)
            ->has('transactions', 2)
            ->where('transactions.0.type', 'addition')
            ->where('transactions.0.amount', 200)
            ->where('transactions.0.created_by', $manager->name)
            ->where('transactions.1.reason', 'Ice')
            ->has('refunds', 2)
            ->where('refunds.0.amount', 40)
            ->where('refunds.0.status', 'approved')
            ->where('refunds.0.payment_method', 'cash')
            ->where('refunds.0.requested_by', $cashier->name)
            ->where('refunds.0.approved_by', $manager->name)
            ->where('ticketCounts', ['open' => 0, 'paid' => 1, 'cancelled' => 1, 'merged' => 0])
        );
});

test('an open shift\'s detail shows live totals', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    paidBurgerTicket($shift, $cashier);
    posTicket($shift, $cashier, 'still-ordering');

    $this->actingAs($manager)
        ->get("/shifts/{$shift->id}")
        ->assertInertia(fn ($page) => $page
            ->where('shift.status', 'open')
            ->where('shift.total_cash', 100)
            ->where('shift.expected_cash', 1100)
            ->where('shift.closing_cash', null)
            ->where('shift.closed_by', null)
            ->where('ticketCounts.open', 1)
            ->where('ticketCounts.paid', 1)
        );
});

test('cashiers cannot open the shift pages and guests are sent to login', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $this->actingAs($cashier)->get('/shifts')->assertForbidden();
    $this->actingAs($cashier)->get("/shifts/{$shift->id}")->assertForbidden();

    auth()->logout();
    $this->get('/shifts')->assertRedirect(route('login'));
});

test('a soft-deleted cash transaction is never shown', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $transaction = app(ShiftTransactionService::class)->AddTransaction($shift, $manager, 'expense', 10, 'Gone');
    app(ShiftTransactionService::class)->DeleteTransaction($transaction, $manager);

    expect(ShiftTransaction::query()->whereKey($transaction->id)->value('deleted_at'))->not->toBeNull();

    $this->actingAs($manager)->get("/shifts/{$shift->id}")
        ->assertInertia(fn ($page) => $page->has('transactions', 0)->where('shift.total_expenses', 0));
});
