<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\User;

/*
| The back-office hub (GET /back-office): counts of what's set up, the setup problems that break
| the POS or the reports ("Needs fixing"), and every raw material and direct-stock item worst first.
*/

beforeEach(fn () => $this->withoutVite());

function hubProps(): array
{
    return test()->actingAs(User::factory()->manager()->create())->get('/back-office')->assertOk()->viewData('page')['props'];
}

function hubItem(Category $category, string $name, array $attributes = []): Item
{
    return Item::create([
        'category_id' => $category->id,
        'name' => $name,
        'base_price' => 100,
        'cost_price' => 40,
        'quantity' => 20,
        'inventory_type' => 'direct',
        'status' => 'available',
        ...$attributes,
    ]);
}

test('each setup problem is listed with a link to fix it, and stock is sorted out, low, then OK', function () {
    $mains = Category::create(['name' => 'Mains', 'status' => 'active', 'is_visible_to_pos' => true]);
    $offMenu = Category::create(['name' => 'Off Menu', 'status' => 'active', 'is_visible_to_pos' => false]);
    $extras = Category::create(['name' => 'Extras', 'type' => 'special', 'status' => 'active', 'is_visible_to_pos' => true]);
    $meats = IngredientGroup::create(['name' => 'Meats']);
    $pork = Ingredient::create(['ingredient_group_id' => $meats->id, 'name' => 'Pork', 'unit' => 'kg', 'quantity' => 0.1, 'cost_per_unit' => 350]);
    $itik = Ingredient::create(['ingredient_group_id' => $meats->id, 'name' => 'Itik', 'unit' => 'piece', 'quantity' => 10, 'cost_per_unit' => 0, 'reorder_level' => 12]);
    Ingredient::create(['ingredient_group_id' => $meats->id, 'name' => 'Garlic', 'unit' => 'kg', 'quantity' => 2, 'cost_per_unit' => 120]);

    hubItem($mains, 'Empty Recipe', ['inventory_type' => 'recipe', 'quantity' => 0, 'cost_price' => 0]);
    hubItem($mains, 'Sisig', ['inventory_type' => 'recipe', 'quantity' => 0, 'cost_price' => 0])
        ->ingredients()->attach($pork->id, ['quantity_required' => 0.2, 'unit' => 'kg']);
    hubItem($mains, 'Fried Itik', ['inventory_type' => 'recipe', 'quantity' => 0, 'cost_price' => 0])
        ->ingredients()->attach($itik->id, ['quantity_required' => 1, 'unit' => 'piece']);
    hubItem($mains, 'Softdrinks', ['quantity' => 0, 'cost_price' => 18]);
    hubItem($mains, 'Water', ['quantity' => 50, 'cost_price' => 0, 'reorder_level' => 10]);
    hubItem($offMenu, 'Secret Dish', ['quantity' => 5, 'cost_price' => 10]);
    hubItem($extras, 'Delivery Fee', ['inventory_type' => 'none', 'quantity' => 0, 'cost_price' => 0, 'entry_mode' => 'price']);

    ModifierGroup::create(['name' => 'Size', 'is_required' => true]);
    $sauce = ModifierGroup::create(['name' => 'Sauce', 'is_required' => true]);
    Modifier::create(['modifier_group_id' => $sauce->id, 'name' => 'Gravy', 'status' => 'active']);

    $props = hubProps();
    $issues = collect($props['issues'])->mapWithKeys(fn (array $issue) => [
        $issue['key'] => collect($issue['records'])->map(fn (array $record) => $record['detail'] ? "{$record['name']} ({$record['detail']})" : $record['name'])->all(),
    ]);

    expect($props['counts'])->toMatchArray([
        'categories' => 3,
        'items' => 7,
        'items_available' => 7,
        'modifier_groups' => 2,
        'ingredient_groups' => 1,
        'ingredients' => 3,
    ])
        ->and($issues->all())->toBe([
            'recipe_without_ingredients' => ['Empty Recipe'],
            'available_but_cannot_be_made' => ['Sisig (a raw material has run out)', 'Softdrinks (out of stock)'],
            'no_cost' => ['Fried Itik (its raw materials have no cost)', 'Water'],
            'required_group_without_modifiers' => ['Size'],
            'hidden_category_with_available_items' => ['Off Menu (1 available item)'],
            'unused_raw_materials' => ['Garlic'],
        ])
        ->and(collect($props['issues'])->firstWhere('key', 'recipe_without_ingredients')['records'][0]['url'])->toEndWith('/items/'.Item::firstWhere('name', 'Empty Recipe')->id.'/edit')
        // Special items (fees, custom) aren't stock: Delivery Fee is absent.
        ->and(collect($props['stock'])->map(fn (array $row) => [$row['status'], $row['name']])->all())->toBe([
            ['out', 'Softdrinks'],
            ['low', 'Itik'],
            ['ok', 'Garlic'],
            ['ok', 'Pork'],
            ['ok', 'Secret Dish'],
            ['ok', 'Water'],
        ])
        ->and(collect($props['stock'])->firstWhere('name', 'Itik'))->toMatchArray([
            'kind' => 'raw material', 'group' => 'Meats', 'unit' => 'piece', 'available' => 10.0, 'reorder_level' => 12.0,
        ]);
});

test('a correctly set up menu reads as all clear', function () {
    $mains = Category::create(['name' => 'Mains', 'status' => 'active', 'is_visible_to_pos' => true]);
    $meats = IngredientGroup::create(['name' => 'Meats']);
    $itik = Ingredient::create(['ingredient_group_id' => $meats->id, 'name' => 'Itik', 'unit' => 'piece', 'quantity' => 40, 'cost_per_unit' => 180]);
    hubItem($mains, 'Fried Itik', ['inventory_type' => 'recipe', 'quantity' => 0, 'cost_price' => 0])
        ->ingredients()->attach($itik->id, ['quantity_required' => 1, 'unit' => 'piece']);
    hubItem($mains, 'Rice');

    $props = hubProps();

    expect($props['issues'])->toBe([])
        ->and(collect($props['stock'])->pluck('status')->unique()->values()->all())->toBe(['ok']);
});

test('reserved stock on open tickets counts against what can be made', function () {
    $cashier = posUser();
    $softdrinks = posItem('Softdrinks', 35, 2);
    $softdrinks->update(['cost_price' => 18]);
    posAddItem(posTicket(posOpenShift($cashier), $cashier, 'john'), $softdrinks, 2);

    $props = hubProps();

    expect(collect($props['issues'])->firstWhere('key', 'available_but_cannot_be_made')['records'][0]['name'])->toBe('Softdrinks')
        ->and(collect($props['stock'])->firstWhere('name', 'Softdrinks'))->toMatchArray(['on_hand' => 2.0, 'reserved' => 2.0, 'available' => 0.0, 'status' => 'out']);
});
