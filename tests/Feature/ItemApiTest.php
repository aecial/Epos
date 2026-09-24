<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\ItemRecipeService;
use Laravel\Sanctum\Sanctum;

function apiCategory(string $name, bool $visibleToPos = true): Category
{
    return Category::create(['name' => $name, 'status' => 'active', 'is_visible_to_pos' => $visibleToPos]);
}

function apiItem(Category $category, string $name, array $overrides = []): Item
{
    return Item::create(array_merge([
        'category_id' => $category->id,
        'name' => $name,
        'base_price' => 100,
        'cost_price' => 40,
        'quantity' => 10,
        'inventory_type' => 'direct',
        'status' => 'available',
    ], $overrides));
}

test('the items endpoints require authentication', function () {
    $this->getJson('/api/v1/items')->assertUnauthorized();
    $this->getJson('/api/v1/items/1')->assertUnauthorized();
});

test('a cashier can list the menu, sorted by name, without cost data', function () {
    $itik = apiCategory('Itik');
    apiItem($itik, 'Sisig Itik', ['base_price' => 380]);
    apiItem($itik, 'Fried Itik', ['base_price' => 295]);

    Sanctum::actingAs(posUser('cashier'));

    $response = $this->getJson('/api/v1/items')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Fried Itik')
        ->assertJsonPath('data.1.name', 'Sisig Itik')
        ->assertJsonPath('data.0.category.name', 'Itik')
        ->assertJsonPath('data.0.status', 'available')
        ->assertJsonPath('data.0.inventory_type', 'direct');

    // Margin data and raw stock columns stay off the till.
    $response->assertJsonMissingPath('data.0.cost_price')
        ->assertJsonMissingPath('data.0.quantity')
        ->assertJsonMissingPath('data.0.reserved_quantity');
});

test('the menu can be filtered by category', function () {
    $itik = apiCategory('Itik');
    $pork = apiCategory('Pork');
    apiItem($itik, 'Fried Itik');
    apiItem($pork, 'Pork Sisig');

    Sanctum::actingAs(posUser());

    $this->getJson("/api/v1/items?category_id={$itik->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Fried Itik')
        ->assertJsonMissing(['name' => 'Pork Sisig']);

    // No filter returns both.
    $this->getJson('/api/v1/items')->assertOk()->assertJsonCount(2, 'data');
});

test('the category filter is validated', function () {
    Sanctum::actingAs(posUser());

    $this->getJson('/api/v1/items?category_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id']);

    $this->getJson('/api/v1/items?category_id=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id']);
});

test('filtering by a category with no items returns an empty list', function () {
    $empty = apiCategory('Desserts');
    apiItem(apiCategory('Itik'), 'Fried Itik');

    Sanctum::actingAs(posUser());

    $this->getJson("/api/v1/items?category_id={$empty->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('hidden items and items in categories hidden from the POS never reach the menu', function () {
    $itik = apiCategory('Itik');
    $backOfficeOnly = apiCategory('Staff Meals', visibleToPos: false);

    apiItem($itik, 'Fried Itik', ['status' => 'available']);
    apiItem($itik, 'Salmon Sinigang', ['status' => 'unavailable']);   // shown, greyed out
    apiItem($itik, 'Secret Special', ['status' => 'hidden']);         // never shown
    apiItem($backOfficeOnly, 'Staff Adobo');                           // category not on POS

    Sanctum::actingAs(posUser());

    $response = $this->getJson('/api/v1/items')->assertOk()->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('name', 'name')->keys()->all())
        ->toBe(['Fried Itik', 'Salmon Sinigang'])
        ->and(collect($response->json('data'))->firstWhere('name', 'Salmon Sinigang')['status'])->toBe('unavailable');

    // Filtering straight to a hidden category doesn't leak its items either.
    $this->getJson("/api/v1/items?category_id={$backOfficeOnly->id}")->assertOk()->assertJsonCount(0, 'data');
});

test('available_stock reflects reservations for direct items, ingredients for recipes, and null when untracked', function () {
    $category = apiCategory('Mains');

    apiItem($category, 'Salmon Sinigang', ['quantity' => 10, 'reserved_quantity' => 2]);
    apiItem($category, 'Water', ['inventory_type' => 'none', 'quantity' => 0]);

    $recipe = apiItem($category, 'Fried Itik', ['inventory_type' => 'recipe', 'quantity' => 0]);
    $group = IngredientGroup::create(['name' => 'Raw', 'status' => 'active']);
    $duck = Ingredient::create([
        'ingredient_group_id' => $group->id, 'name' => 'Itik', 'unit' => 'piece',
        'quantity' => 10, 'reserved_quantity' => 0, 'cost_per_unit' => 100, 'status' => 'active',
    ]);
    app(ItemRecipeService::class)->ReplaceItemRecipe($recipe, [
        ['ingredient_id' => $duck->id, 'quantity_required' => 2, 'unit' => 'piece'],
    ]);

    // A recipe dish nobody has configured ingredients for yet: must not crash the menu.
    apiItem($category, 'Half-built Dish', ['inventory_type' => 'recipe', 'quantity' => 0]);

    Sanctum::actingAs(posUser());

    $byName = collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->keyBy('name');

    expect($byName['Salmon Sinigang']['available_stock'])->toEqual(8)   // 10 on hand - 2 reserved
        ->and($byName['Fried Itik']['available_stock'])->toEqual(5)     // 10 ducks / 2 per serving
        ->and($byName['Water']['available_stock'])->toBeNull()          // untracked
        ->and($byName['Half-built Dish']['available_stock'])->toBeNull();
});

test('items list only their active modifiers with prices and groups, in the configured order', function () {
    $item = apiItem(apiCategory('Mains'), 'Fried Itik');
    $size = ModifierGroup::create(['name' => 'Size', 'is_required' => true]);

    $large = Modifier::create(['modifier_group_id' => $size->id, 'name' => 'Large']);
    $small = Modifier::create(['modifier_group_id' => $size->id, 'name' => 'Small']);
    $retired = Modifier::create(['modifier_group_id' => $size->id, 'name' => 'Retired', 'status' => 'inactive']);
    $offForThisItem = Modifier::create(['modifier_group_id' => $size->id, 'name' => 'Jumbo']);

    $item->modifiers()->attach($large->id, ['price_modifier' => 50, 'status' => 'active', 'display_order' => 2]);
    $item->modifiers()->attach($small->id, ['price_modifier' => 0, 'status' => 'active', 'display_order' => 1]);
    $item->modifiers()->attach($retired->id, ['price_modifier' => 5, 'status' => 'active', 'display_order' => 3]);
    $item->modifiers()->attach($offForThisItem->id, ['price_modifier' => 90, 'status' => 'inactive', 'display_order' => 4]);

    Sanctum::actingAs(posUser());

    $modifiers = $this->getJson('/api/v1/items')->assertOk()->json('data.0.modifiers');

    expect(collect($modifiers)->pluck('name')->all())->toBe(['Small', 'Large'])
        ->and((float) $modifiers[1]['price_modifier'])->toBe(50.0)
        ->and($modifiers[1]['group'])->toBe(['id' => $size->id, 'name' => 'Size', 'is_required' => true]);
});

test('a single item can be fetched', function () {
    $item = apiItem(apiCategory('Itik'), 'Fried Itik', ['base_price' => 295]);

    Sanctum::actingAs(posUser());

    $this->getJson("/api/v1/items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $item->id)
        ->assertJsonPath('data.name', 'Fried Itik')
        ->assertJsonPath('data.category.name', 'Itik')
        ->assertJsonMissingPath('data.cost_price');
});

test('a single hidden item, or one in a POS-hidden category, is a clean 404', function () {
    $hidden = apiItem(apiCategory('Itik'), 'Secret Special', ['status' => 'hidden']);
    $staff = apiItem(apiCategory('Staff Meals', visibleToPos: false), 'Staff Adobo');

    Sanctum::actingAs(posUser());

    $this->getJson("/api/v1/items/{$hidden->id}")->assertNotFound()->assertJsonPath('success', false);
    $this->getJson("/api/v1/items/{$staff->id}")->assertNotFound();
    $this->getJson('/api/v1/items/999999')->assertNotFound();
});
