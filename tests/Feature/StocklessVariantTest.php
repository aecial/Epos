<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\TicketItemIngredient;
use App\Models\User;
use App\Services\KdsService;
use App\Services\RefundService;
use App\Services\SalesReportService;
use App\Services\TicketService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| Stockless variants: a modifier like "Lagi" that, when picked, makes its ticket line take no stock
| at all (neither the dish's own nor its raw materials), cost ₱0, and never change the price. The
| kitchen sees it; the customer receipt doesn't. Items Sold reports it as its own "Dish · Lagi" row.
*/

/** Sisig Itik: recipe, 1 Itik (₱180) per serving, ₱249. Itik has 10 in stock. Lagi attached at a (forced-to-0) ₱15. */
function lagiSetup(): array
{
    $group = IngredientGroup::create(['name' => 'Meats']);
    $itik = Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Itik', 'unit' => 'piece', 'quantity' => 10, 'cost_per_unit' => 180]);
    $category = Category::create(['name' => 'Itik', 'status' => 'active', 'is_visible_to_pos' => true]);
    $sisig = Item::create(['category_id' => $category->id, 'name' => 'Sisig Itik', 'base_price' => 249, 'cost_price' => 0, 'inventory_type' => 'recipe', 'status' => 'available']);
    $sisig->ingredients()->attach($itik->id, ['quantity_required' => 1, 'unit' => 'piece']);

    $variants = ModifierGroup::create(['name' => 'Variant']);
    $lagi = Modifier::create(['modifier_group_id' => $variants->id, 'name' => 'Lagi', 'status' => 'active', 'is_stockless_variant' => true]);
    // Attached straight to the pivot with a price, to prove the sale itself forces ₱0.
    $sisig->modifiers()->attach($lagi->id, ['price_modifier' => 15, 'status' => 'active', 'display_order' => 1]);

    return [$sisig, $itik, $lagi];
}

function lagiAdd(Ticket $ticket, Item $item, int $quantity, array $modifierIds = []): TicketItem
{
    return app(TicketService::class)->AddItem($ticket->fresh(), $item->fresh(), $quantity, $modifierIds);
}

function lagiPay(Ticket $ticket, User $cashier): Ticket
{
    $total = (float) $ticket->fresh()->total;

    return posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => $total, 'tendered_amount' => $total]]);
}

test('a Lagi line takes no stock, costs ₱0 and keeps the dish price; a normal line beside it still takes stock', function () {
    [$sisig, $itik, $lagi] = lagiSetup();
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');

    $lagiLine = lagiAdd($ticket, $sisig, 2, [$lagi->id]);
    $normalLine = lagiAdd($ticket, $sisig, 1);

    expect((float) $itik->fresh()->reserved_quantity)->toBe(1.0)
        ->and($lagiLine->is_stockless)->toBeTrue()
        ->and((float) $lagiLine->item_cost_price)->toBe(0.0)
        ->and((float) $lagiLine->line_total)->toBe(498.0)
        ->and((float) $lagiLine->modifiers()->firstOrFail()->price)->toBe(0.0)
        ->and($lagiLine->modifiers()->firstOrFail()->is_stockless_variant)->toBeTrue()
        ->and($normalLine->is_stockless)->toBeFalse()
        ->and((float) $normalLine->item_cost_price)->toBe(180.0);

    lagiPay($ticket, $cashier);

    expect((float) $itik->fresh()->quantity)->toBe(9.0)
        ->and((float) $itik->fresh()->reserved_quantity)->toBe(0.0)
        ->and(TicketItemIngredient::where('ticket_item_id', $lagiLine->id)->exists())->toBeFalse()
        ->and(TicketItemIngredient::where('ticket_item_id', $normalLine->id)->exists())->toBeTrue();
});

test('Lagi can be sold even when the dish has no stock left', function () {
    [$sisig, $itik, $lagi] = lagiSetup();
    $itik->update(['quantity' => 0]);
    $cashier = posUser();

    $line = lagiAdd(posTicket(posOpenShift($cashier), $cashier, 'john'), $sisig, 3, [$lagi->id]);

    expect($line->is_stockless)->toBeTrue()->and((float) $itik->fresh()->reserved_quantity)->toBe(0.0);
});

test('changing quantity, voiding and cancelling a Lagi line never touch stock', function () {
    [$sisig, $itik, $lagi] = lagiSetup();
    User::factory()->manager()->create(['passcode' => '2468']);
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $first = posTicket($shift, $cashier, 'john');
    $lagiLine = lagiAdd($first, $sisig, 2, [$lagi->id]);
    lagiAdd($first, $sisig, 1);
    app(TicketService::class)->UpdateItemQuantity($lagiLine->fresh(), 5);
    app(TicketService::class)->UpdateItemQuantity($lagiLine->fresh(), 1);
    app(TicketService::class)->VoidItem($lagiLine->fresh(), $cashier, '2468');

    $second = posTicket($shift, $cashier, 'mary');
    lagiAdd($second, $sisig, 4, [$lagi->id]);
    app(TicketService::class)->CancelTicket($second->fresh(), $cashier);

    // Only the normal Sisig Itik on john's ticket holds stock.
    expect((float) $itik->fresh()->reserved_quantity)->toBe(1.0)
        ->and((float) $itik->fresh()->quantity)->toBe(10.0);
});

test('approving a refund on a Lagi line puts nothing back', function () {
    [$sisig, $itik, $lagi] = lagiSetup();
    User::factory()->manager()->create(['passcode' => '2468']);
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $line = lagiAdd($ticket, $sisig, 2, [$lagi->id]);
    $paid = lagiPay($ticket, $cashier);

    $refund = app(RefundService::class)->RequestRefund($paid, $paid->charges()->firstOrFail(), $cashier, [
        ['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 249],
    ], 'Cold');
    app(RefundService::class)->ApproveRefund($refund, $cashier, '2468');

    expect((float) $itik->fresh()->quantity)->toBe(10.0);
});

test('the kitchen sees Lagi; the customer receipt does not', function () {
    [$sisig, , $lagi] = lagiSetup();
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    lagiAdd($ticket, $sisig, 1, [$lagi->id]);

    $card = app(KdsService::class)->GetOpenOrders()[0];
    expect($card['items'][0]['item_name'])->toBe('Sisig Itik')
        ->and($card['items'][0]['modifiers'])->toBe([['name' => 'Lagi']]);

    $line = lagiPay($ticket, $cashier)->receipts()->firstOrFail()->payload['items'][0];
    expect($line['name'])->toBe('Sisig Itik')
        ->and($line['modifiers'])->toBe([])
        ->and($line['line_total'])->toEqual(249);
});

test('Items Sold shows Lagi as its own ₱0-cost row, and raw materials ignore it', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    [$sisig, , $lagi] = lagiSetup();
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    lagiAdd($ticket, $sisig, 2, [$lagi->id]);
    lagiAdd($ticket, $sisig, 1);
    lagiPay($ticket, $cashier);

    $from = now()->startOfDay();
    $to = now()->endOfDay();
    $report = app(SalesReportService::class)->ItemsSold($from, $to);
    $rows = collect($report['rows'])->keyBy('name');

    expect($rows->keys()->all())->toEqualCanonicalizing(['Sisig Itik', 'Sisig Itik · Lagi'])
        ->and($rows['Sisig Itik · Lagi'])->toMatchArray(['variant' => 'Lagi', 'quantity' => 2, 'net_sales' => 498.0, 'cost' => 0.0, 'profit' => 498.0, 'missing_cost' => false])
        ->and($rows['Sisig Itik'])->toMatchArray(['variant' => null, 'quantity' => 1, 'net_sales' => 249.0, 'cost' => 180.0, 'profit' => 69.0]);

    $raw = app(SalesReportService::class)->RawMaterialUsage($from, $to, $report['rows']);

    expect($raw['rawMaterials'][0])->toMatchArray(['name' => 'Itik', 'used' => 1.0])
        ->and($raw['rawMaterials'][0]['dishes'][0])->toMatchArray(['name' => 'Sisig Itik', 'servings' => 1, 'net_sales' => 249.0])
        ->and($raw['directItems'])->toBe([['name' => 'Sisig Itik · Lagi', 'quantity' => 2]]);
});

test('turning the flag off later changes nothing already sold', function () {
    [$sisig, $itik, $lagi] = lagiSetup();
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $line = lagiAdd($ticket, $sisig, 1, [$lagi->id]);
    $paid = lagiPay($ticket, $cashier);

    $lagi->update(['is_stockless_variant' => false]);

    expect($line->fresh()->is_stockless)->toBeTrue()
        ->and($line->fresh()->modifiers()->firstOrFail()->is_stockless_variant)->toBeTrue()
        ->and($paid->receipts()->firstOrFail()->payload['items'][0]['modifiers'])->toBe([])
        ->and((float) $itik->fresh()->quantity)->toBe(10.0);
});

test('the back office saves the flag and keeps a stockless modifier at ₱0 on every dish', function () {
    [$sisig] = lagiSetup();
    $manager = User::factory()->manager()->create();
    $group = ModifierGroup::firstWhere('name', 'Variant');
    $spicy = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Spicy', 'status' => 'active']);
    $sisig->modifiers()->attach($spicy->id, ['price_modifier' => 10, 'status' => 'active', 'display_order' => 2]);

    $this->actingAs($manager)->post('/modifiers', ['modifier_group_id' => $group->id, 'name' => 'Usual', 'is_stockless_variant' => true])->assertSessionHasNoErrors();
    expect(Modifier::firstWhere('name', 'Usual')->is_stockless_variant)->toBeTrue();

    // Turning an attached, priced modifier stockless zeroes its price on every dish.
    $this->actingAs($manager)->patch("/modifiers/{$spicy->id}", ['is_stockless_variant' => true])->assertSessionHasNoErrors();
    expect((float) $sisig->modifiers()->whereKey($spicy->id)->firstOrFail()->pivot->price_modifier)->toBe(0.0);

    // Attaching it from the item form with a price still saves ₱0.
    $this->actingAs($manager)->patch("/items/{$sisig->id}", ['modifiers' => [['modifier_id' => $spicy->id, 'price_modifier' => 20]]])->assertSessionHasNoErrors();
    expect((float) $sisig->modifiers()->whereKey($spicy->id)->firstOrFail()->pivot->price_modifier)->toBe(0.0);
});

test('the POS menu marks stockless variants and shows them at ₱0', function () {
    [$sisig, , $lagi] = lagiSetup();
    Sanctum::actingAs(posUser(), ['*']);

    $modifiers = collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->firstWhere('id', $sisig->id)['modifiers'];

    expect($modifiers[0])->toMatchArray(['id' => $lagi->id, 'name' => 'Lagi', 'price_modifier' => '0.00', 'is_stockless_variant' => true]);
});

test('the full Lagi flow, set up through the back office the way a manager does it', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 18:00:00', 'Asia/Manila'));
    $manager = User::factory()->manager()->create();

    // 1. Back office: raw material, category, the one Sisig Itik dish, then the Lagi variant.
    $this->actingAs($manager)->post('/ingredient-groups', ['name' => 'Raw Materials'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/ingredients', [
        'ingredient_group_id' => IngredientGroup::firstWhere('name', 'Raw Materials')->id,
        'name' => 'Itik', 'unit' => 'piece', 'quantity' => 41, 'cost_per_unit' => 180,
    ])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/categories', ['name' => 'Itik', 'status' => 'active', 'is_visible_to_pos' => true])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/modifier-groups', ['name' => 'Variant', 'is_required' => false])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/modifiers', [
        'modifier_group_id' => ModifierGroup::firstWhere('name', 'Variant')->id, 'name' => 'Lagi', 'status' => 'active', 'is_stockless_variant' => true,
    ])->assertSessionHasNoErrors();

    $itik = Ingredient::firstWhere('name', 'Itik');
    $lagi = Modifier::firstWhere('name', 'Lagi');
    $this->actingAs($manager)->post('/items', [
        'category_id' => Category::firstWhere('name', 'Itik')->id,
        'name' => 'Sisig Itik', 'base_price' => 380, 'inventory_type' => 'recipe', 'status' => 'available',
        'ingredients' => [['ingredient_id' => $itik->id, 'quantity_required' => 1, 'unit' => 'piece']],
        'modifiers' => [['modifier_id' => $lagi->id, 'price_modifier' => 0]],
    ])->assertRedirectToRoute('item-management');
    $sisig = Item::firstWhere('name', 'Sisig Itik');

    // 2. POS: two Sisig Itik with Lagi and one normal, then paid in cash.
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    lagiAdd($ticket, $sisig, 2, [$lagi->id]);
    lagiAdd($ticket, $sisig, 1);

    $kitchen = collect(app(KdsService::class)->GetOpenOrders()[0]['items'])
        ->map(fn (array $line) => trim("{$line['quantity']}x {$line['item_name']} ".collect($line['modifiers'])->pluck('name')->implode(', ')))
        ->all();
    expect($kitchen)->toBe(['2x Sisig Itik Lagi', '1x Sisig Itik'])
        ->and((float) $itik->fresh()->reserved_quantity)->toBe(1.0);

    $paid = lagiPay($ticket, $cashier);

    // 3. Receipt: no "Lagi", same price. Stock: only the normal serving took Itik.
    $receipt = collect($paid->receipts()->firstOrFail()->payload['items'])
        ->map(fn (array $line) => ['name' => $line['name'], 'quantity' => $line['quantity'], 'modifiers' => $line['modifiers'], 'line_total' => (float) $line['line_total']])
        ->all();
    expect($receipt)->toBe([
        ['name' => 'Sisig Itik', 'quantity' => 2, 'modifiers' => [], 'line_total' => 760.0],
        ['name' => 'Sisig Itik', 'quantity' => 1, 'modifiers' => [], 'line_total' => 380.0],
    ])
        ->and((float) $itik->fresh()->quantity)->toBe(40.0);

    // 4. Items Sold: Lagi is its own ₱0-cost row beside the normal dish.
    $rows = collect(app(SalesReportService::class)->ItemsSold(now()->startOfDay(), now()->endOfDay())['rows'])->keyBy('name');
    expect($rows['Sisig Itik · Lagi'])->toMatchArray(['quantity' => 2, 'net_sales' => 760.0, 'cost' => 0.0, 'profit' => 760.0, 'missing_cost' => false])
        ->and($rows['Sisig Itik'])->toMatchArray(['quantity' => 1, 'net_sales' => 380.0, 'cost' => 180.0, 'profit' => 200.0]);
});
