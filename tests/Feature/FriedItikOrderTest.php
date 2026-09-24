<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Services\InventoryService;
use App\Services\ItemRecipeService;
use Laravel\Sanctum\Sanctum;

/*
| Scenario: one customer orders
|   - 1 Fried Itik       (RECIPE item: consumes Itik + Cooking Oil + Garlic ingredients)
|   - 1 Salmon Sinigang  (DIRECT item: owns its own stock)
|   - 2 Rice             (DIRECT item; the brief didn't specify, so treated as direct stock)
| and pays half cash / half GCash.
|
| Prices: 350 + 250 + (2 x 40) = 680  ->  340 cash + 340 gcash.
*/

/** @return array{itik: Item, sinigang: Item, rice: Item, duck: Ingredient, oil: Ingredient, garlic: Ingredient} */
function friedItikMenu(): array
{
    $category = Category::create(['name' => 'Main Dishes', 'status' => 'active', 'is_visible_to_pos' => true]);
    $group = IngredientGroup::create(['name' => 'Raw Materials', 'status' => 'active']);

    $ingredient = fn (string $name, string $unit, float $quantity) => Ingredient::create([
        'ingredient_group_id' => $group->id,
        'name' => $name,
        'unit' => $unit,
        'quantity' => $quantity,
        'cost_per_unit' => 10,
        'status' => 'active',
    ]);

    $duck = $ingredient('Itik', 'piece', 10);
    $oil = $ingredient('Cooking Oil', 'liter', 5);
    $garlic = $ingredient('Garlic', 'gram', 1000);

    $dish = fn (string $name, float $price, string $type, int $quantity) => Item::create([
        'category_id' => $category->id,
        'name' => $name,
        'base_price' => $price,
        'cost_price' => 0,
        'quantity' => $quantity,
        'inventory_type' => $type,
        'status' => 'available',
    ]);

    $friedItik = $dish('Fried Itik', 350, 'recipe', 0);
    // One serving of Fried Itik uses 1 duck + 0.25 L oil + 20 g garlic.
    app(ItemRecipeService::class)->ReplaceItemRecipe($friedItik, [
        ['ingredient_id' => $duck->id, 'quantity_required' => 1, 'unit' => 'piece'],
        ['ingredient_id' => $oil->id, 'quantity_required' => 0.25, 'unit' => 'liter'],
        ['ingredient_id' => $garlic->id, 'quantity_required' => 20, 'unit' => 'gram'],
    ]);

    return [
        'itik' => $friedItik->fresh(),
        'sinigang' => $dish('Salmon Sinigang', 250, 'direct', 20),
        'rice' => $dish('Rice', 40, 'direct', 30),
        'duck' => $duck,
        'oil' => $oil,
        'garlic' => $garlic,
    ];
}

/** [on-hand, reserved] as floats, read fresh from the database. */
function stockOf(Item|Ingredient $model): array
{
    $fresh = $model->fresh();

    return [(float) $fresh->quantity, (float) $fresh->reserved_quantity];
}

/** Creates the ticket and rings up the whole order through the real API. Returns the ticket id. */
function placeFriedItikOrder($test, array $menu): int
{
    $ticketId = $test->postJson('/api/v1/tickets', [
        'terminal_id' => 'POS-01',
        'customer_name' => 'john',
        'order_type' => 'dine_in',
    ])->assertCreated()->json('data.id');

    foreach ([[$menu['itik'], 1], [$menu['sinigang'], 1], [$menu['rice'], 2]] as [$item, $quantity]) {
        $test->postJson("/api/v1/tickets/{$ticketId}/items", ['item_id' => $item->id, 'quantity' => $quantity])
            ->assertCreated();
    }

    return $ticketId;
}

function paySplitHalfCashHalfGcash($test, int $ticketId)
{
    return $test->postJson("/api/v1/tickets/{$ticketId}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 340, 'tendered_amount' => 500],
            ['payment_method' => 'gcash', 'amount' => 340, 'payment_reference' => 'GC-FRIED-ITIK'],
        ],
    ]);
}

test('ordering reserves stock in both inventory modes but does not deduct yet', function () {
    $cashier = posUser();
    posOpenShift($cashier);
    $menu = friedItikMenu();
    Sanctum::actingAs($cashier);

    // Before ordering: the recipe is limited by its scarcest ingredient (10 ducks -> 10 servings).
    expect(app(InventoryService::class)->AvailableForItem($menu['itik']))->toBe(10.0);

    $ticketId = placeFriedItikOrder($this, $menu);

    $this->getJson("/api/v1/tickets/{$ticketId}")
        ->assertOk()
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.subtotal', 680)
        ->assertJsonPath('data.total', 680);

    // RECIPE path: the *ingredients* are reserved, the dish itself holds no stock.
    expect(stockOf($menu['duck']))->toBe([10.0, 1.0])
        ->and(stockOf($menu['oil']))->toBe([5.0, 0.25])
        ->and(stockOf($menu['garlic']))->toBe([1000.0, 20.0])
        ->and(stockOf($menu['itik']))->toBe([0.0, 0.0]);

    // DIRECT path: the item's own stock is reserved.
    expect(stockOf($menu['sinigang']))->toBe([20.0, 1.0])
        ->and(stockOf($menu['rice']))->toBe([30.0, 2.0]);

    // Reserved stock is no longer sellable: 9 ducks left for new Fried Itik orders.
    expect(app(InventoryService::class)->AvailableForItem($menu['itik']))->toBe(9.0);
});

test('paying deducts recipe ingredients and direct stock and clears every reservation', function () {
    $cashier = posUser();
    posOpenShift($cashier);
    $menu = friedItikMenu();
    Sanctum::actingAs($cashier);

    $ticketId = placeFriedItikOrder($this, $menu);
    paySplitHalfCashHalfGcash($this, $ticketId)->assertOk()->assertJsonPath('data.status', 'paid');

    // RECIPE path: 1 serving consumed 1 duck, 0.25 L oil, 20 g garlic. Reservation released.
    expect(stockOf($menu['duck']))->toBe([9.0, 0.0])
        ->and(stockOf($menu['oil']))->toBe([4.75, 0.0])
        ->and(stockOf($menu['garlic']))->toBe([980.0, 0.0])
        // The dish itself never held stock, and must not go negative.
        ->and(stockOf($menu['itik']))->toBe([0.0, 0.0]);

    // DIRECT path: 1 sinigang and 2 rice consumed.
    expect(stockOf($menu['sinigang']))->toBe([19.0, 0.0])
        ->and(stockOf($menu['rice']))->toBe([28.0, 0.0]);

    expect(app(InventoryService::class)->AvailableForItem($menu['itik']))->toBe(9.0);
});

test('a paid ticket cannot be charged again, so stock is never deducted twice', function () {
    $cashier = posUser();
    posOpenShift($cashier);
    $menu = friedItikMenu();
    Sanctum::actingAs($cashier);

    $ticketId = placeFriedItikOrder($this, $menu);
    paySplitHalfCashHalfGcash($this, $ticketId)->assertOk();

    paySplitHalfCashHalfGcash($this, $ticketId)->assertStatus(409);

    expect(stockOf($menu['duck']))->toBe([9.0, 0.0])
        ->and(stockOf($menu['sinigang']))->toBe([19.0, 0.0])
        ->and(stockOf($menu['rice']))->toBe([28.0, 0.0]);
});

test('a recipe dish cannot be ordered when one ingredient is short, and nothing is reserved', function () {
    $cashier = posUser();
    posOpenShift($cashier);
    $menu = friedItikMenu();
    $menu['duck']->update(['quantity' => 0]); // out of duck; oil and garlic are plentiful
    Sanctum::actingAs($cashier);

    $ticketId = $this->postJson('/api/v1/tickets', [
        'terminal_id' => 'POS-01', 'customer_name' => 'john', 'order_type' => 'dine_in',
    ])->json('data.id');

    $this->postJson("/api/v1/tickets/{$ticketId}/items", ['item_id' => $menu['itik']->id, 'quantity' => 1])
        ->assertStatus(409);

    // All-or-nothing: the plentiful ingredients were not partially reserved.
    expect(stockOf($menu['oil']))->toBe([5.0, 0.0])
        ->and(stockOf($menu['garlic']))->toBe([1000.0, 0.0])
        ->and($this->getJson("/api/v1/tickets/{$ticketId}")->json('data.items'))->toBeEmpty();
});

test('a half cash half gcash payment is recorded and the shift sales totals are correct', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier); // starting cash 1000
    $menu = friedItikMenu();
    Sanctum::actingAs($cashier);

    $ticketId = placeFriedItikOrder($this, $menu);

    $paid = paySplitHalfCashHalfGcash($this, $ticketId)->assertOk();

    // The two charges, and change for the cash part (paid 500 for a 340 share).
    // Compared as floats: MySQL serialises decimals as "340.00", SQLite as 340.
    $paid->assertJsonCount(2, 'data.charges');
    [$cash, $gcash] = $paid->json('data.charges');

    expect($cash['payment_method'])->toBe('cash')
        ->and((float) $cash['amount'])->toBe(340.0)
        ->and((float) $cash['tendered_amount'])->toBe(500.0)
        ->and((float) $cash['change_due'])->toBe(160.0)
        ->and($gcash['payment_method'])->toBe('gcash')
        ->and((float) $gcash['amount'])->toBe(340.0)
        ->and($gcash['change_due'])->toBeNull()
        ->and($gcash['payment_reference'])->toBe('GC-FRIED-ITIK')
        // One receipt per charge, each for its own half.
        ->and((float) $cash['receipt']['amount'])->toBe(340.0)
        ->and((float) $gcash['receipt']['amount'])->toBe(340.0);

    // Live "sales so far" display while the shift is still open.
    $this->getJson('/api/v1/shifts/active')
        ->assertOk()
        ->assertJsonPath('data.total_revenue', 680)
        ->assertJsonPath('data.total_cash', 340)
        ->assertJsonPath('data.total_gcash', 340)
        ->assertJsonPath('data.total_additions', 0)
        ->assertJsonPath('data.total_expenses', 0)
        ->assertJsonPath('data.total_refunds', 0)
        // Drawer should hold starting 1000 + the 340 of cash sales. GCash never enters it.
        ->assertJsonPath('data.expected_cash', 1340);

    // Cash + GCash must add back up to the ticket total and to the receipt history.
    $receipts = $this->getJson('/api/v1/receipts')->assertOk()->json('data');
    expect(collect($receipts)->sum(fn ($r) => (float) $r['amount']))->toBe(680.0);

    // Closing with exactly the expected cash: the frozen snapshot matches, no shortage/excess.
    $this->putJson("/api/v1/shifts/{$shift->id}/close", ['closing_cash' => 1340])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.total_revenue', 680)
        ->assertJsonPath('data.total_cash', 340)
        ->assertJsonPath('data.total_gcash', 340)
        ->assertJsonPath('data.expected_cash', 1340)
        ->assertJsonPath('data.closing_cash', 1340)
        ->assertJsonPath('data.discrepancy', 0);
});
