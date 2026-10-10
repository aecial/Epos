<?php

use App\Models\PosDevice;
use App\Models\SyncIssue;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Support\Carbon;

/*
| What the back office shows after an outage: the Sync review list a manager works through, the
| badges, offline tickets marked as such, each phone's sync state, and the shift report's sales
| that arrived after the shift closed.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-10-10 18:00:00', 'Asia/Manila'));
});

/**
 * A manager, and a cashier's phone that sold Fried Itik offline past its last unit (stock issue)
 * and removed a Burger without a passcode (void issue).
 *
 * @return array{manager: User, phone: array{user: User, token: string, device: PosDevice}, ticket: Ticket}
 */
function syncReviewScenario(): array
{
    $manager = posUser('manager');
    $phone = syncPhone();
    $shift = posOpenShift($manager);
    $shift->update(['opened_at' => Carbon::parse('2026-10-10 09:00', 'Asia/Manila')]);
    $itik = posItem('Fried Itik', 450, 0);
    $burger = posItem('Burger', 100);
    $ticketUuid = syncUuid();
    $burgerLine = syncUuid();

    syncSend($phone['token'], [
        syncAction('ticket.create', ['ticket_uuid' => $ticketUuid, 'terminal_id' => 'POS', 'customer_name' => 'John', 'order_type' => 'dine_in', 'offline_label' => 'P9-001'], '2026-10-10 12:00'),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticketUuid, 'line_uuid' => syncUuid(), 'item_id' => $itik->id, 'quantity' => 1, 'unit_price' => 450], '2026-10-10 12:01'),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticketUuid, 'line_uuid' => $burgerLine, 'item_id' => $burger->id, 'quantity' => 1, 'unit_price' => 100], '2026-10-10 12:02'),
        syncAction('ticket.void_item', ['line_uuid' => $burgerLine, 'reason' => 'Wrong order'], '2026-10-10 12:03'),
    ])->assertOk();

    return ['manager' => $manager, 'phone' => $phone, 'ticket' => Ticket::sole()];
}

test('a manager sees what needs review, newest first, with the ticket and the phone', function () {
    ['manager' => $manager, 'phone' => $phone, 'ticket' => $ticket] = syncReviewScenario();

    $this->actingAs($manager)
        ->get('/sync-issues')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SyncIssuesPage')
            ->has('issues', 2)
            ->where('issues.0.type', 'offline_void')
            ->where('issues.0.ticket', ['id' => $ticket->id, 'order_number' => '#001', 'customer_name' => 'John', 'offline_label' => 'P9-001'])
            ->where('issues.0.device.code', $phone['device']->code)
            ->where('issues.0.user', $phone['user']->name)
            ->where('issues.0.reviewed_at', null)
            ->where('issues.1.type', 'stock_short')
            ->where('counts', ['offline_void' => 1, 'stock_short' => 1])
            ->where('filters', ['status' => 'unreviewed', 'type' => null])
        );
});

test('marking an issue reviewed records who and when, and takes it off the list', function () {
    ['manager' => $manager] = syncReviewScenario();
    $issue = SyncIssue::firstWhere('type', 'stock_short');

    $this->actingAs($manager)->from('/sync-issues')->patch("/sync-issues/{$issue->id}/review")->assertRedirect('/sync-issues');

    expect($issue->fresh()->reviewed_by)->toBe($manager->id)
        ->and($issue->fresh()->reviewed_at)->not->toBeNull();

    $props = fn (string $query) => $this->actingAs($manager)->get("/sync-issues{$query}")->viewData('page')['props'];

    expect(collect($props('')['issues'])->pluck('type')->all())->toBe(['offline_void'])
        ->and(collect($props('?status=reviewed')['issues'])->pluck('type')->all())->toBe(['stock_short'])
        ->and($props('?status=reviewed')['issues'][0]['reviewed_by'])->toBe($manager->name)
        ->and(collect($props('?status=all&type=offline_void')['issues'])->pluck('type')->all())->toBe(['offline_void']);
});

test('the unreviewed count reaches the sidebar badge and the dashboard', function () {
    ['manager' => $manager] = syncReviewScenario();

    $this->actingAs($manager)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('unreviewedSyncIssues', 2)
            ->where('attention.sync_issues.count', 2)
        );
});

test('a cashier is signed out of the review page and cannot mark anything reviewed', function () {
    ['phone' => $phone] = syncReviewScenario();
    $issue = SyncIssue::first();

    $this->actingAs($phone['user'])->get('/sync-issues')->assertRedirect('/login');
    $this->actingAs($phone['user'])->patch("/sync-issues/{$issue->id}/review")->assertRedirect('/login');

    expect($issue->fresh()->reviewed_at)->toBeNull();
});

test('an unknown filter is rejected', function () {
    $this->actingAs(posUser('manager'))
        ->from('/sync-issues')
        ->get('/sync-issues?type=gremlins')
        ->assertRedirect('/sync-issues')
        ->assertSessionHasErrors('type');
});

test('offline tickets are marked, filterable, and show the item removed without a passcode', function () {
    ['manager' => $manager, 'ticket' => $offline, 'phone' => $phone] = syncReviewScenario();
    $online = posTicket($offline->shift, $manager, 'Mary');

    $this->actingAs($manager)
        ->get('/tickets?offline=1')
        ->assertInertia(fn ($page) => $page
            ->has('tickets', 1)
            ->where('tickets.0.id', $offline->id)
            ->where('tickets.0.created_offline', true)
            ->where('tickets.0.offline_label', 'P9-001')
        );

    expect(collect($this->actingAs($manager)->get('/tickets')->viewData('page')['props']['tickets'])->pluck('id')->sort()->values()->all())
        ->toBe([$offline->id, $online->id]);

    $this->actingAs($manager)
        ->get("/tickets/{$offline->id}")
        ->assertInertia(fn ($page) => $page
            ->where('ticket.created_offline', true)
            ->where('ticket.offline_label', 'P9-001')
            ->where('ticket.device.code', $phone['device']->code)
            ->where('items.0.added_offline', true)
            ->where('items.1.voided_offline', true)
            ->where('items.1.void_reason', 'Wrong order')
            ->where('items.1.voided_by', null)
            ->where('items.1.voided_requested_by', $phone['user']->name)
        );
});

test('a cashier\'s devices page shows each phone\'s code, last sync and waiting actions', function () {
    ['manager' => $manager, 'phone' => $phone] = syncReviewScenario();
    syncSend($phone['token'], [], pending: 4)->assertOk();

    $this->actingAs($manager)
        ->get("/users/{$phone['user']->id}/sessions")
        ->assertInertia(fn ($page) => $page
            ->where('sessions.0.device_code', $phone['device']->code)
            ->where('sessions.0.pending_actions', 4)
            ->where('sessions.0.last_synced_at', fn ($value) => $value !== null)
        );
});

test('the shift report lists what arrived after the shift closed, apart from its totals', function () {
    $manager = posUser('manager');
    $phone = syncPhone();
    $shift = posOpenShift($manager);
    $shift->update(['opened_at' => Carbon::parse('2026-10-10 09:00', 'Asia/Manila')]);
    app(ShiftService::class)->CloseShift($shift, $manager, 1000);
    $burger = posItem('Burger', 100);
    $ticketUuid = syncUuid();
    $managerPhone = syncPhone('manager', 'POS-02');
    // The power comes back half an hour after the shift was closed.
    $this->travelTo(Carbon::parse('2026-10-10 18:30:00', 'Asia/Manila'));

    syncSend($phone['token'], [
        syncAction('shift.open', ['shift_uuid' => $shiftUuid = syncUuid(), 'starting_cash' => 1000], '2026-10-10 12:00'),
        syncAction('ticket.create', ['ticket_uuid' => $ticketUuid, 'shift_uuid' => $shiftUuid, 'terminal_id' => 'POS', 'customer_name' => 'John', 'order_type' => 'dine_in', 'offline_label' => 'P1-004'], '2026-10-10 12:05'),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticketUuid, 'line_uuid' => syncUuid(), 'item_id' => $burger->id, 'quantity' => 3, 'unit_price' => 100], '2026-10-10 12:05'),
        syncAction('ticket.charge', ['ticket_uuid' => $ticketUuid, 'charges' => [['charge_uuid' => syncUuid(), 'payment_method' => 'cash', 'amount' => 300, 'tendered_amount' => 300]]], '2026-10-10 12:10'),
    ])->assertOk();
    syncSend($managerPhone['token'], [
        syncAction('shift_transaction.add', ['transaction_uuid' => syncUuid(), 'shift_id' => $shift->id, 'type' => 'expense', 'amount' => 50, 'reason' => 'Ice'], '2026-10-10 12:30'),
    ])->assertOk();

    $this->actingAs($manager)
        ->get("/shifts/{$shift->id}")
        ->assertInertia(fn ($page) => $page
            ->where('shift.total_revenue', 0)
            ->has('lateSync.tickets', 1)
            ->where('lateSync.tickets.0.offline_label', 'P1-004')
            ->where('lateSync.tickets.0.total', 300)
            ->has('lateSync.transactions', 1)
            ->where('lateSync.transactions.0.reason', 'Ice')
            ->where('lateSync.cash_sales', 300)
            ->where('lateSync.drawer_change', 250)
        );
});
