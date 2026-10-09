<?php

use App\Models\Refund;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Support\Carbon;

/*
| Back-office Refunds page (GET /refunds, view only): refunds still waiting for a passcode on the
| POS pinned on top, the full history with filters, and totals by status and by person.
| Admin/manager only; the pending count is also shared with every page for the sidebar badge.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'Asia/Manila'));
});

/** A paid ticket of $quantity Burgers (₱100 each), paid by $method. */
function refundPagePaidTicket(Shift $shift, User $cashier, string $customer, string $method = 'cash', int $quantity = 2): Ticket
{
    $ticket = posTicket($shift, $cashier, $customer);
    posAddItem($ticket, posItem('Burger '.uniqid(), 100), $quantity);
    $charge = $method === 'cash'
        ? ['payment_method' => 'cash', 'amount' => 100 * $quantity, 'tendered_amount' => 100 * $quantity]
        : ['payment_method' => 'gcash', 'amount' => 100 * $quantity, 'payment_reference' => 'GC-'.$customer];

    return posPay($ticket->fresh(), $cashier, [$charge]);
}

function refundPageRequest(Ticket $ticket, User $by, float $amount, string $reason): Refund
{
    return app(RefundService::class)->RequestRefund($ticket, $ticket->charges()->firstOrFail(), $by, [
        ['ticket_item_id' => $ticket->items()->firstOrFail()->id, 'quantity' => 1, 'amount' => $amount],
    ], $reason);
}

/**
 * Anna: an approved cash refund (₱100, yesterday) and a pending GCash one (₱50, today).
 * Ben: a rejected cash refund (₱80, today). Kring decides both decided ones.
 *
 * @return array{manager: User, anna: User, ben: User, approved: Refund, rejected: Refund, pending: Refund, john: Ticket, mary: Ticket}
 */
function refundPageScenario(): array
{
    $manager = User::factory()->create(['role' => 'manager', 'name' => 'Kring', 'passcode' => '2468']);
    $anna = User::factory()->create(['role' => 'cashier', 'name' => 'Anna']);
    $ben = User::factory()->create(['role' => 'cashier', 'name' => 'Ben']);
    $shift = posOpenShift($anna);

    test()->travelTo(Carbon::parse('2026-10-07 15:00:00', 'Asia/Manila'));
    $john = refundPagePaidTicket($shift, $anna, 'john');
    $approved = refundPageRequest($john, $anna, 100, 'Cold');
    app(RefundService::class)->ApproveRefund($approved, $anna, '2468');

    test()->travelTo(Carbon::parse('2026-10-08 09:00:00', 'Asia/Manila'));
    $mary = refundPagePaidTicket($shift, $anna, 'mary', 'gcash');
    $pending = refundPageRequest($mary, $anna, 50, 'Spilled');

    test()->travelTo(Carbon::parse('2026-10-08 09:30:00', 'Asia/Manila'));
    $pedro = refundPagePaidTicket($shift, $ben, 'pedro');
    $rejected = refundPageRequest($pedro, $ben, 80, 'Changed mind');
    app(RefundService::class)->RejectRefund($rejected, $ben, '2468');

    test()->travelTo(Carbon::parse('2026-10-08 10:00:00', 'Asia/Manila'));

    return compact('manager', 'anna', 'ben', 'approved', 'rejected', 'pending', 'john', 'mary');
}

function refundPageProps(User $viewer, string $query = ''): array
{
    return test()->actingAs($viewer)->get("/refunds{$query}")->assertOk()->viewData('page')['props'];
}

test('a manager sees pending refunds pinned, the history newest first, and totals by status and person', function () {
    ['manager' => $manager, 'approved' => $approved, 'rejected' => $rejected, 'pending' => $pending, 'john' => $john] = refundPageScenario();

    $this->actingAs($manager)
        ->get('/refunds')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('RefundManagementPage')
            ->has('pending', 1)
            ->where('pending.0.id', $pending->id)
            ->where('pending.0.payment_method', 'gcash')
            ->where('pending.0.requested_by', 'Anna')
            ->has('refunds', 3)
            ->where('refunds.0.id', $rejected->id)
            ->where('refunds.1.id', $pending->id)
            ->where('refunds.2.id', $approved->id)
            ->where('refunds.2.status', 'approved')
            ->where('refunds.2.amount', 100)
            ->where('refunds.2.reason', 'Cold')
            ->where('refunds.2.decided_by', 'Kring')
            ->where('refunds.2.ticket', ['id' => $john->id, 'order_number' => $john->order_number, 'customer_name' => 'john'])
            ->where('refunds.2.receipt_number', $john->receipts()->value('receipt_number'))
            ->where('refunds.2.items.0.quantity', 1)
            ->where('refunds.0.decided_by', 'Kring')
            ->where('summary.approved_count', 1)
            ->where('summary.approved_total', 100)
            ->where('summary.approved_cash', 100)
            ->where('summary.approved_gcash', 0)
            ->where('summary.rejected_count', 1)
            ->where('summary.rejected_total', 80)
            ->where('summary.pending_count', 1)
            ->where('summary.pending_total', 50)
            ->where('summary.by_requester', [
                ['name' => 'Anna', 'requested' => 2, 'approved' => 1, 'approved_total' => 100, 'rejected' => 0, 'pending' => 1],
                ['name' => 'Ben', 'requested' => 1, 'approved' => 0, 'approved_total' => 0, 'rejected' => 1, 'pending' => 0],
            ])
            ->where('summary.by_decider', [['name' => 'Kring', 'approved' => 1, 'approved_total' => 100, 'rejected' => 1]])
        );
});

test('the history filters by status, method, requested date and search; the cards ignore the status filter', function () {
    ['manager' => $manager, 'approved' => $approved, 'rejected' => $rejected, 'pending' => $pending, 'mary' => $mary] = refundPageScenario();

    $ids = fn (string $query): array => collect(refundPageProps($manager, $query)['refunds'])->pluck('id')->all();

    expect($ids('?status=rejected'))->toBe([$rejected->id])
        ->and($ids('?payment_method=gcash'))->toBe([$pending->id])
        ->and($ids('?date_to=2026-10-07'))->toBe([$approved->id])
        ->and($ids('?date_from=2026-10-08'))->toBe([$rejected->id, $pending->id])
        ->and($ids('?search=pedr'))->toBe([$rejected->id])
        ->and($ids('?search='.urlencode($mary->receipts()->value('receipt_number'))))->toBe([$pending->id]);

    $filtered = refundPageProps($manager, '?status=rejected');

    expect($filtered['summary']['approved_count'])->toBe(1)
        ->and($filtered['summary']['pending_count'])->toBe(1)
        // Pending refunds stay pinned whatever the filters.
        ->and(collect($filtered['pending'])->pluck('id')->all())->toBe([$pending->id]);
});

test('the pending count is shared with every back-office page for the sidebar badge', function () {
    ['manager' => $manager] = refundPageScenario();

    $this->actingAs($manager)->get('/dashboard')->assertInertia(fn ($page) => $page->where('pendingRefunds', 1));
    $this->actingAs($manager)->get('/shifts')->assertInertia(fn ($page) => $page->where('pendingRefunds', 1));
});

test('an invalid filter is rejected', function () {
    $this->actingAs(posUser('manager'))
        ->from('/refunds')
        ->get('/refunds?status=refunded')
        ->assertRedirect('/refunds')
        ->assertSessionHasErrors('status');
});

test('a cashier is signed out; a guest is sent to login', function () {
    $this->actingAs(posUser())->get('/refunds')->assertRedirect('/login');

    auth()->logout();
    $this->get('/refunds')->assertRedirect('/login');
});
