<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use App\Services\ShiftTransactionService;
use App\Services\TicketService;
use Illuminate\Support\Carbon;

/*
| Back-office Items Sold report (GET /sales): what sold in a period (default today, Asia/Manila),
| per item - qty, gross, discount share, net sales, refunds, cost, profit - and a summary down to
| net profit after expenses. A line is sold when its ticket is paid, whatever the shift's state.
| Admin/manager only.
*/

// These assert the Inertia props; the page component itself is compiled by Vite.
beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'Asia/Manila'));
});

/** Fried Itik: a recipe item selling at ₱295, costing 1 Itik at ₱180. */
function salesItik(): Item
{
    $group = IngredientGroup::firstOrCreate(['name' => 'Kitchen']);
    $itik = Ingredient::firstOrCreate(['name' => 'Itik'], ['ingredient_group_id' => $group->id, 'unit' => 'piece', 'quantity' => 100, 'cost_per_unit' => 180]);
    $category = Category::firstOrCreate(['name' => 'Itik'], ['status' => 'active', 'is_visible_to_pos' => true]);

    $item = Item::firstOrCreate(['name' => 'Fried Itik'], [
        'category_id' => $category->id,
        'base_price' => 295,
        'cost_price' => 0,
        'inventory_type' => 'recipe',
        'status' => 'available',
    ]);
    $item->ingredients()->syncWithoutDetaching([$itik->id => ['quantity_required' => 1, 'unit' => 'piece']]);

    return $item;
}

/** Rice: a direct item selling at ₱20, costing ₱8. */
function salesRice(): Item
{
    return Item::firstWhere('name', 'Rice') ?? tap(posItem('Rice', 20), fn (Item $rice) => $rice->update(['cost_price' => 8]));
}

function salesPayCash(Ticket $ticket, User $cashier): Ticket
{
    $total = (float) $ticket->fresh()->total;

    return posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => $total, 'tendered_amount' => $total]]);
}

function salesReport(User $viewer, string $query = ''): array
{
    return test()->actingAs($viewer)->get("/sales{$query}")->assertOk()->viewData('page')['props'];
}

test('today\'s paid items are grouped with gross, discount share, net sales, cost and profit, even while the shift is open', function () {
    $manager = posUser('manager');
    $manager->update(['passcode' => '2468']);
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $first = posTicket($shift, $cashier, 'john');
    posAddItem($first, salesItik(), 2);
    posAddItem($first, salesRice());
    $voided = posAddItem($first, salesRice(), 3);
    app(TicketService::class)->VoidItem($voided, $cashier, '2468');
    salesPayCash($first, $cashier);

    $discounted = posTicket($shift, $cashier, 'mary');
    posAddItem($discounted, salesItik());
    app(TicketService::class)->SetDiscount($discounted->fresh(), 0, 10);
    salesPayCash($discounted, $cashier);

    // Merged into a ticket that's then paid: its line counts once, on the paid target.
    $target = posTicket($shift, $cashier, 'pedro');
    posAddItem($target, salesRice());
    $source = posTicket($shift, $cashier, 'ana');
    posAddItem($source, salesRice());
    app(TicketService::class)->MergeTickets($target->fresh(), [$source->id], $cashier);
    salesPayCash($target, $cashier);

    $stillOpen = posTicket($shift, $cashier, 'rosa');
    posAddItem($stillOpen, salesItik());
    $cancelled = posTicket($shift, $cashier, 'lito');
    posAddItem($cancelled, salesItik());
    app(TicketService::class)->CancelTicket($cancelled->fresh(), $cashier);

    $this->actingAs($manager)->get('/sales')->assertInertia(fn ($page) => $page
        ->component('SalesReportPage')
        ->where('period', ['date_from' => '2026-10-08', 'date_to' => '2026-10-08', 'today' => '2026-10-08'])
        ->has('rows', 2)
        // Fried Itik: 3 sold for ₱885, ₱29.50 off the discounted ticket, 3 × ₱180 cost.
        ->where('rows.0.name', 'Fried Itik')
        ->where('rows.0.quantity', 3)
        ->where('rows.0.gross_sales', 885)
        ->where('rows.0.discount', 29.5)
        ->where('rows.0.net_sales', 855.5)
        ->where('rows.0.cost', 540)
        ->where('rows.0.profit', 315.5)
        ->where('rows.0.margin', 36.9)
        ->where('rows.0.missing_cost', false)
        // Rice: 1 on john's ticket + 2 on the merged ticket; the voided 3 aren't sold.
        ->where('rows.1.name', 'Rice')
        ->where('rows.1.quantity', 3)
        ->where('rows.1.net_sales', 60)
        ->where('rows.1.cost', 24)
        ->where('rows.1.profit', 36)
        ->where('summary.tickets_paid', 3)
        ->where('summary.items_sold', 6)
        ->where('summary.gross_sales', 945)
        ->where('summary.discounts', 29.5)
        ->where('summary.net_sales', 915.5)
        ->where('summary.cost', 564)
        ->where('summary.gross_profit', 351.5)
        ->where('summary.net_profit', 351.5)
        ->where('openTickets', ['count' => 1, 'total' => 295])
    );
});

test('approved refunds and expenses come off profit; pending refunds and deleted expenses don\'t', function () {
    $manager = posUser('manager');
    $manager->update(['passcode' => '2468']);
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, salesItik(), 2);
    $paid = salesPayCash($ticket, $cashier);
    $charge = $paid->charges()->firstOrFail();

    $refunds = app(RefundService::class);
    $approved = $refunds->RequestRefund($paid, $charge, $cashier, [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 295]], 'Cold');
    $refunds->ApproveRefund($approved, $cashier, '2468');
    $refunds->RequestRefund($paid, $charge, $cashier, [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 100]], 'Pending');

    $transactions = app(ShiftTransactionService::class);
    $transactions->AddTransaction($shift, $cashier, 'expense', 150, 'Ice');
    $transactions->AddTransaction($shift, $cashier, 'addition', 500, 'Change fund');
    $mistake = $transactions->AddTransaction($shift, $cashier, 'expense', 999, 'Typo');
    $transactions->DeleteTransaction($mistake, $manager);

    $report = salesReport($manager);

    // 2 × ₱295 sold, ₱295 refunded, 2 × ₱180 cost: 590 − 295 − 360 = −65, then −150 expenses.
    expect($report['rows'][0])->toMatchArray([
        'quantity' => 2,
        'refunded_quantity' => 1,
        'refunded' => 295,
        'profit' => -65,
    ])->and($report['summary'])->toMatchArray([
        'refunds' => 295,
        'gross_profit' => -65,
        'expenses' => 150,
        'net_profit' => -215,
    ]);
});

test('days follow Philippine time, and a period can span several days', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $this->travelTo(Carbon::parse('2026-10-07 23:30:00', 'Asia/Manila'));
    $lateNight = posTicket($shift, $cashier, 'john');
    posAddItem($lateNight, salesRice());
    salesPayCash($lateNight, $cashier);

    $this->travelTo(Carbon::parse('2026-10-08 00:10:00', 'Asia/Manila'));
    $afterMidnight = posTicket($shift, $cashier, 'mary');
    posAddItem($afterMidnight, salesRice(), 2);
    salesPayCash($afterMidnight, $cashier);

    expect(salesReport($manager)['summary']['items_sold'])->toBe(2)
        ->and(salesReport($manager, '?date_from=2026-10-07')['summary']['items_sold'])->toBe(1)
        ->and(salesReport($manager, '?date_from=2026-10-07')['openTickets'])->toBeNull()
        ->and(salesReport($manager, '?date_from=2026-10-07&date_to=2026-10-08')['summary']['items_sold'])->toBe(3)
        ->and(salesReport($manager, '?date_from=2026-10-09')['rows'])->toBe([]);
});

test('fee and custom lines get their own rows, and a dish sold without a cost is flagged', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');

    $special = Category::create(['name' => 'Extras', 'type' => 'special', 'status' => 'active', 'is_visible_to_pos' => true]);
    $fee = Item::create(['category_id' => $special->id, 'name' => 'Delivery Fee', 'base_price' => 0, 'cost_price' => 0, 'inventory_type' => 'none', 'entry_mode' => 'price', 'status' => 'available']);
    $custom = Item::create(['category_id' => $special->id, 'name' => 'Custom', 'base_price' => 0, 'cost_price' => 0, 'inventory_type' => 'none', 'entry_mode' => 'name_price', 'status' => 'available']);

    $service = app(TicketService::class);
    $service->AddItem($ticket, $fee, 1, [], unitPrice: 50);
    $service->AddItem($ticket->fresh(), $custom, 1, [], unitPrice: 40, customName: 'Extra sauce');
    $service->AddItem($ticket->fresh(), $custom, 2, [], unitPrice: 15, customName: 'Extra plate');
    posAddItem($ticket->fresh(), posItem('Softdrinks', 30));
    salesPayCash($ticket, $cashier);

    $rows = collect(salesReport($manager)['rows'])->keyBy('name');

    expect($rows->keys()->all())->toEqualCanonicalizing(['Delivery Fee', 'Extra sauce', 'Extra plate', 'Softdrinks'])
        ->and($rows['Delivery Fee']['line_type'])->toBe('fee')
        ->and($rows['Delivery Fee']['missing_cost'])->toBeFalse()
        ->and($rows['Extra plate']['line_type'])->toBe('custom')
        ->and($rows['Extra plate']['quantity'])->toBe(2)
        ->and($rows['Softdrinks']['missing_cost'])->toBeTrue()
        // Fees aren't dishes: 1 + 2 + 1, not counting the delivery fee.
        ->and(salesReport($manager)['summary']['items_sold'])->toBe(4);
});

test('an invalid period is rejected', function () {
    $this->actingAs(posUser('manager'))
        ->from('/sales')
        ->get('/sales?date_from=2026-10-08&date_to=2026-10-01')
        ->assertRedirect('/sales')
        ->assertSessionHasErrors('date_to');
});

test('a cashier is forbidden; a guest is sent to login', function () {
    $this->actingAs(posUser())->get('/sales')->assertForbidden();

    auth()->logout();
    $this->get('/sales')->assertRedirect('/login');
});

/**
 * A recipe item built from $recipe (ingredient name => quantity per serving). Ingredients:
 * Itik ₱180/piece and Pork ₱350/kg (Meats), Egg ₱10/piece (Dairy & Eggs).
 */
function rawRecipeItem(string $name, float $price, array $recipe): Item
{
    $catalog = [
        'Itik' => ['Meats', 'piece', 180],
        'Pork' => ['Meats', 'kg', 350],
        'Egg' => ['Dairy & Eggs', 'piece', 10],
    ];
    $category = Category::firstOrCreate(['name' => 'Mains'], ['status' => 'active', 'is_visible_to_pos' => true]);
    $item = Item::create(['category_id' => $category->id, 'name' => $name, 'base_price' => $price, 'cost_price' => 0, 'inventory_type' => 'recipe', 'status' => 'available']);

    foreach ($recipe as $ingredientName => $quantity) {
        [$groupName, $unit, $cost] = $catalog[$ingredientName];
        $group = IngredientGroup::firstOrCreate(['name' => $groupName]);
        $ingredient = Ingredient::firstOrCreate(['name' => $ingredientName], ['ingredient_group_id' => $group->id, 'unit' => $unit, 'quantity' => 100, 'cost_per_unit' => $cost]);
        $item->ingredients()->attach($ingredient->id, ['quantity_required' => $quantity, 'unit' => $unit]);
    }

    return $item;
}

test('by raw material: usage per ingredient with a dish breakdown, refunds returned, direct items listed apart', function () {
    $manager = posUser('manager');
    $manager->update(['passcode' => '2468']);
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $friedItik = rawRecipeItem('Fried Itik', 295, ['Itik' => 1]);
    $sisig = rawRecipeItem('Sisig', 180, ['Pork' => 0.2, 'Egg' => 1]);
    $itikSisig = rawRecipeItem('Itik Sisig', 249, ['Itik' => 0.5, 'Egg' => 1]);

    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $friedItik, 2);
    $sisigLine = posAddItem($ticket, $sisig, 3);
    posAddItem($ticket, $itikSisig, 2);
    posAddItem($ticket, salesRice());
    $paid = salesPayCash($ticket, $cashier);

    // An open ticket's ingredients are only reserved, not used.
    posAddItem(posTicket($shift, $cashier, 'mary'), $friedItik, 5);

    $refund = app(RefundService::class)->RequestRefund($paid, $paid->charges()->firstOrFail(), $cashier, [
        ['ticket_item_id' => $sisigLine->id, 'quantity' => 1, 'amount' => 180],
    ]);
    app(RefundService::class)->ApproveRefund($refund, $cashier, '2468');

    $report = salesReport($manager, '?view=raw-materials');
    $materials = collect($report['rawMaterials']);

    expect($report['view'])->toBe('raw-materials')
        // Sorted by group, then name: Dairy & Eggs (Egg), then Meats (Itik, Pork).
        ->and($materials->pluck('name')->all())->toBe(['Egg', 'Itik', 'Pork'])
        ->and($materials[1])->toMatchArray([
            'group' => 'Meats',
            'unit' => 'piece',
            'used' => 3.0,
            'returned' => 0.0,
            'net_used' => 3.0,
            'cost' => 540,
            'stock' => 97.0,
            'available' => 92.0,
        ])
        ->and(collect($materials[1]['dishes'])->map(fn (array $dish) => [$dish['name'], $dish['servings'], $dish['used']])->all())
        ->toBe([['Fried Itik', 2, 2.0], ['Itik Sisig', 2, 1.0]])
        // The dish's own figures, from the By item rows: 2 × ₱249, cost 2 × (0.5 × ₱180 + ₱10).
        ->and($materials[1]['dishes'][1])->toMatchArray(['net_sales' => 498, 'profit' => 298])
        // Pork: 3 Sisig took 0.6 kg; the approved refund of 1 put 0.2 kg back.
        ->and($materials[2])->toMatchArray(['used' => 0.6, 'returned' => 0.2, 'net_used' => 0.4, 'cost' => 140])
        // Egg is used by two dishes: 3 + 2, less 1 returned.
        ->and($materials[0])->toMatchArray(['used' => 5.0, 'returned' => 1.0, 'net_used' => 4.0, 'cost' => 40])
        ->and($report['directItems'])->toBe([['name' => 'Rice', 'quantity' => 1]]);

    // The By item tab doesn't compute raw materials.
    expect(salesReport($manager)['rawMaterials'])->toBe([]);
});

test('by raw material only counts usage from tickets paid in the period', function () {
    $manager = posUser('manager');
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $friedItik = rawRecipeItem('Fried Itik', 295, ['Itik' => 1]);

    $this->travelTo(Carbon::parse('2026-10-07 20:00:00', 'Asia/Manila'));
    $yesterday = posTicket($shift, $cashier, 'john');
    posAddItem($yesterday, $friedItik, 4);
    salesPayCash($yesterday, $cashier);

    $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $today = posTicket($shift, $cashier, 'mary');
    posAddItem($today, $friedItik, 1);
    salesPayCash($today, $cashier);

    expect(salesReport($manager, '?view=raw-materials')['rawMaterials'][0]['net_used'])->toBe(1.0)
        ->and(salesReport($manager, '?view=raw-materials&date_from=2026-10-07')['rawMaterials'][0]['net_used'])->toBe(4.0)
        ->and(salesReport($manager, '?view=raw-materials&date_from=2026-10-09')['rawMaterials'])->toBe([]);
});

test('an unknown report view is rejected', function () {
    $this->actingAs(posUser('manager'))
        ->from('/sales')
        ->get('/sales?view=ingredients')
        ->assertRedirect('/sales')
        ->assertSessionHasErrors('view');
});
