<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\User;
use App\Services\ItemRecipeService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| available_stock on the POS menu and the item-management page must be computed from data
| the list query already loaded - not re-read per item. Asserted as "query count does not
| grow with item count" so unrelated eager-load changes don't break it.
*/

function seedStockItems(int $pairs): void
{
    $category = Category::firstOrCreate(['name' => 'Stock Test'], ['status' => 'active', 'is_visible_to_pos' => true]);
    $group = IngredientGroup::firstOrCreate(['name' => 'Stock Test Raw'], ['status' => 'active']);

    for ($i = 0; $i < $pairs; $i++) {
        Item::create([
            'category_id' => $category->id,
            'name' => 'Direct '.Item::count(),
            'base_price' => 100,
            'cost_price' => 40,
            'quantity' => 10,
            'inventory_type' => 'direct',
            'status' => 'available',
        ]);

        $ingredient = Ingredient::create([
            'ingredient_group_id' => $group->id,
            'name' => 'Raw '.Ingredient::count(),
            'unit' => 'piece',
            'quantity' => 10,
            'reserved_quantity' => 0,
            'cost_per_unit' => 5,
            'status' => 'active',
        ]);

        $recipe = Item::create([
            'category_id' => $category->id,
            'name' => 'Recipe '.Item::count(),
            'base_price' => 200,
            'cost_price' => 0,
            'quantity' => 0,
            'inventory_type' => 'recipe',
            'status' => 'available',
        ]);

        app(ItemRecipeService::class)->ReplaceItemRecipe($recipe, [
            ['ingredient_id' => $ingredient->id, 'quantity_required' => 2, 'unit' => 'piece'],
        ]);
    }
}

function countQueries(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

test('the POS menu runs the same number of queries regardless of how many items it lists', function () {
    Sanctum::actingAs(posUser(), ['*']);

    seedStockItems(1);
    $small = countQueries(fn () => $this->getJson('/api/v1/items')->assertOk()->assertJsonCount(2, 'data'));

    seedStockItems(5);
    $large = countQueries(fn () => $this->getJson('/api/v1/items')->assertOk()->assertJsonCount(12, 'data'));

    expect($large)->toBe($small);
});

test('the item-management page runs the same number of queries regardless of how many items it lists', function () {
    $manager = User::factory()->create(['role' => 'manager']);

    seedStockItems(1);
    $small = countQueries(fn () => $this->actingAs($manager)->get('/item-management')->assertOk());

    seedStockItems(5);
    $large = countQueries(fn () => $this->actingAs($manager)->get('/item-management')->assertOk());

    expect($large)->toBe($small);
});

test('the menu still reports correct stock computed from loaded data', function () {
    Sanctum::actingAs(posUser(), ['*']);

    seedStockItems(1);

    $byName = collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->keyBy('inventory_type');

    expect($byName['direct']['available_stock'])->toEqual(10)
        ->and($byName['recipe']['available_stock'])->toEqual(5); // 10 on hand / 2 per serving
});
