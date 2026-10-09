<?php

use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Support\Carbon;

/*
| Back-office dashboard (GET /dashboard): the open shift, today so far against the same clock time
| yesterday, sales by hour, the payment mix, top items, what needs attention and the last 7 days.
| Built from the same services as the report pages; a sale counts when its ticket is paid.
*/

beforeEach(fn () => $this->withoutVite());

function dashboardAt(string $time): void
{
    test()->travelTo(Carbon::parse($time, 'Asia/Manila'));
}

function dashboardSell(Shift $shift, User $cashier, string $customer, array $lines, string $method = 'cash'): Ticket
{
    $ticket = posTicket($shift, $cashier, $customer);

    foreach ($lines as [$item, $quantity]) {
        posAddItem($ticket->fresh(), $item, $quantity);
    }

    $total = (float) $ticket->fresh()->total;
    $charge = $method === 'cash'
        ? ['payment_method' => 'cash', 'amount' => $total, 'tendered_amount' => $total]
        : ['payment_method' => 'gcash', 'amount' => $total, 'payment_reference' => 'GC-'.$customer];

    return posPay($ticket->fresh(), $cashier, [$charge]);
}

function dashboardProps(): array
{
    return test()->actingAs(User::factory()->manager()->create())->get('/dashboard')->assertOk()->viewData('page')['props'];
}

test('with no shift and no sales, the dashboard is all zeros and all clear', function () {
    dashboardAt('2026-10-08 14:00:00');

    $props = dashboardProps();

    expect($props['shift'])->toBeNull()
        ->and($props['today'])->toMatchArray(['net_sales' => 0, 'tickets_paid' => 0, 'average_ticket' => 0, 'margin' => null])
        ->and($props['hourly'])->toBe([])
        ->and($props['paymentMix'])->toEqual(['cash' => 0, 'gcash' => 0])
        ->and($props['topItems'])->toBe([])
        ->and($props['attention'])->toMatchArray(['running_low' => [], 'missing_cost' => []])
        ->and($props['attention']['pending_refunds']['count'])->toBe(0)
        ->and($props['attention']['late_kitchen_orders']['count'])->toBe(0)
        ->and(collect($props['lastSevenDays'])->pluck('date')->all())
        ->toBe(['2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);
});

test('a busy day: shift, today vs the same time yesterday, hours, payment mix, top items, attention and 7 days', function () {
    $manager = User::factory()->manager()->create(['passcode' => '2468']);
    $cashier = posUser();

    dashboardAt('2026-10-07 09:00:00');
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);
    $burger->update(['cost_price' => 40, 'reorder_level' => 100]);
    $rice = posItem('Rice', 20);

    // Yesterday: 11:00 counts toward "so far" at 14:00; 18:00 doesn't, but shows by hour and in 7 days.
    dashboardAt('2026-10-07 11:00:00');
    dashboardSell($shift, $cashier, 'john', [[$burger, 2]]);
    dashboardAt('2026-10-07 18:00:00');
    dashboardSell($shift, $cashier, 'mary', [[$burger, 1]], 'gcash');

    // Today.
    dashboardAt('2026-10-08 10:15:00');
    $pedro = dashboardSell($shift, $cashier, 'pedro', [[$burger, 3]]);
    dashboardAt('2026-10-08 12:30:00');
    dashboardSell($shift, $cashier, 'ana', [[$burger, 1], [$rice, 1]], 'gcash');

    dashboardAt('2026-10-08 13:00:00');
    app(RefundService::class)->RequestRefund($pedro, $pedro->charges()->firstOrFail(), $cashier, [
        ['ticket_item_id' => $pedro->items()->firstOrFail()->id, 'quantity' => 1, 'amount' => 100],
    ], 'Cold');

    // Kitchen: one order waiting 30 min (late), one 10 min (fine).
    dashboardAt('2026-10-08 13:30:00');
    posAddItem(posTicket($shift, $cashier, 'late'), $rice, 1);
    dashboardAt('2026-10-08 13:50:00');
    posAddItem(posTicket($shift, $cashier, 'fresh'), $rice, 1);

    $group = IngredientGroup::create(['name' => 'Meats']);
    Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Itik', 'unit' => 'piece', 'quantity' => 5, 'reorder_level' => 8, 'cost_per_unit' => 180]);
    Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Pork', 'unit' => 'kg', 'quantity' => 20, 'reorder_level' => 5, 'cost_per_unit' => 350]);

    dashboardAt('2026-10-08 14:00:00');
    $props = dashboardProps();

    // Drawer: ₱1,000 start + ₱200 (john) + ₱300 (pedro) cash; GCash never touches the drawer.
    expect($props['shift'])->toMatchArray(['id' => $shift->id, 'starting_cash' => 1000, 'expected_cash' => 1500])
        ->and($props['shift']['open_tickets'])->toEqual(['count' => 2, 'total' => 40])
        // Today: ₱300 + ₱120 over 2 tickets; cost 4 burgers × ₱40, rice has no cost.
        ->and($props['today'])->toMatchArray(['net_sales' => 420, 'tickets_paid' => 2, 'average_ticket' => 210, 'gross_profit' => 260, 'net_profit' => 260, 'refunds' => 0])
        // Yesterday up to 14:00: only john's ₱200, cost 2 × ₱40.
        ->and($props['yesterday'])->toMatchArray(['net_sales' => 200, 'tickets_paid' => 1, 'gross_profit' => 120])
        ->and($props['hourly'])->toEqual([
            ['hour' => 10, 'today' => 300, 'yesterday' => 0],
            ['hour' => 11, 'today' => 0, 'yesterday' => 200],
            ['hour' => 12, 'today' => 120, 'yesterday' => 0],
            ['hour' => 18, 'today' => 0, 'yesterday' => 100],
        ])
        ->and($props['paymentMix'])->toEqual(['cash' => 300, 'gcash' => 120])
        ->and($props['topItems'])->toEqual([
            ['name' => 'Burger', 'quantity' => 4, 'net_sales' => 400],
            ['name' => 'Rice', 'quantity' => 1, 'net_sales' => 20],
        ])
        ->and($props['attention']['pending_refunds']['count'])->toBe(1)
        ->and(Carbon::parse($props['attention']['pending_refunds']['oldest_requested_at'])->format('H:i'))->toBe('13:00')
        ->and($props['attention']['late_kitchen_orders']['count'])->toBe(1)
        ->and(collect($props['attention']['running_low'])->map(fn (array $low) => [$low['kind'], $low['name'], $low['available'], $low['reorder_level']])->all())
        ->toBe([['raw material', 'Itik', 5.0, 8.0], ['item', 'Burger', 43, 100]])
        ->and($props['attention']['missing_cost'])->toBe(['Rice'])
        ->and(collect($props['lastSevenDays'])->keyBy('date')['2026-10-07'])->toMatchArray(['net_sales' => 300, 'net_profit' => 180])
        ->and(collect($props['lastSevenDays'])->keyBy('date')['2026-10-08'])->toMatchArray(['net_sales' => 420, 'net_profit' => 260]);
});

test('a guest is sent to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
