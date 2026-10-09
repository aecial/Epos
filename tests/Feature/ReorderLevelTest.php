<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\User;

/*
| Optional reorder levels: a raw material (ingredients.reorder_level, in its unit) or a direct-stock
| item (items.reorder_level, whole units) is running low when its available stock - quantity minus
| what open tickets reserve - is at or below the level. No level, no warning.
*/

function reorderManager(): User
{
    return User::factory()->manager()->create();
}

function reorderIngredient(float $quantity, float $reserved, ?float $level): Ingredient
{
    $group = IngredientGroup::firstOrCreate(['name' => 'Meats']);

    return Ingredient::create([
        'ingredient_group_id' => $group->id,
        'name' => 'Itik '.uniqid(),
        'unit' => 'piece',
        'quantity' => $quantity,
        'reserved_quantity' => $reserved,
        'reorder_level' => $level,
        'cost_per_unit' => 180,
    ]);
}

test('a manager sets and clears a raw material\'s reorder level', function () {
    $group = IngredientGroup::create(['name' => 'Meats']);

    $this->actingAs(reorderManager())->post('/ingredients', [
        'ingredient_group_id' => $group->id,
        'name' => 'Pork',
        'unit' => 'kg',
        'quantity' => 12,
        'cost_per_unit' => 350,
        'reorder_level' => 2.5,
    ])->assertSessionHasNoErrors();

    $pork = Ingredient::firstWhere('name', 'Pork');
    expect((float) $pork->reorder_level)->toBe(2.5);

    $this->actingAs(reorderManager())->patch("/ingredients/{$pork->id}", ['reorder_level' => ''])->assertSessionHasNoErrors();
    expect($pork->fresh()->reorder_level)->toBeNull();
});

test('a manager sets a direct item\'s reorder level; recipe and untracked items never keep one', function () {
    $category = Category::create(['name' => 'Drinks', 'status' => 'active', 'is_visible_to_pos' => true]);
    $base = ['category_id' => $category->id, 'base_price' => 35, 'cost_price' => 18, 'quantity' => 40, 'status' => 'available', 'reorder_level' => 12];

    $this->actingAs(reorderManager())->post('/items', [...$base, 'name' => 'Softdrinks', 'inventory_type' => 'direct'])->assertRedirectToRoute('item-management');
    $this->actingAs(reorderManager())->post('/items', [...$base, 'name' => 'Water', 'inventory_type' => 'none'])->assertRedirectToRoute('item-management');

    $softdrinks = Item::firstWhere('name', 'Softdrinks');
    expect($softdrinks->reorder_level)->toBe(12)
        ->and(Item::firstWhere('name', 'Water')->reorder_level)->toBeNull();

    // Switching to untracked stock drops the level.
    $this->actingAs(reorderManager())->patch("/items/{$softdrinks->id}", ['inventory_type' => 'none', 'reorder_level' => 12]);
    expect($softdrinks->fresh()->reorder_level)->toBeNull();
});

test('a negative or fractional item reorder level is rejected', function () {
    $category = Category::create(['name' => 'Drinks', 'status' => 'active', 'is_visible_to_pos' => true]);
    $base = ['category_id' => $category->id, 'name' => 'Softdrinks', 'base_price' => 35, 'inventory_type' => 'direct'];

    $this->actingAs(reorderManager())->post('/items', [...$base, 'reorder_level' => -1])->assertSessionHasErrors('reorder_level');
    $this->actingAs(reorderManager())->post('/items', [...$base, 'reorder_level' => 2.5])->assertSessionHasErrors('reorder_level');
    $this->actingAs(reorderManager())->post('/ingredients', ['name' => 'Pork', 'unit' => 'kg', 'reorder_level' => -1])->assertSessionHasErrors('reorder_level');
});

test('a raw material is running low at or below its level, counting reserved stock as gone', function () {
    // 10 on hand, 3 reserved by open tickets: 7 available.
    expect(reorderIngredient(10, 3, 7)->isRunningLow())->toBeTrue()
        ->and(reorderIngredient(10, 3, 6.9)->isRunningLow())->toBeFalse()
        ->and(reorderIngredient(10, 0, 7)->isRunningLow())->toBeFalse()
        ->and(reorderIngredient(0, 0, null)->isRunningLow())->toBeFalse();
});

test('a direct item is running low at or below its level; other inventory types never are', function () {
    $item = posItem('Softdrinks', 35, 10);
    $item->update(['reserved_quantity' => 2, 'reorder_level' => 8]);
    expect($item->fresh()->isRunningLow())->toBeTrue();

    $item->update(['reorder_level' => 7]);
    expect($item->fresh()->isRunningLow())->toBeFalse();

    $item->update(['reorder_level' => 50, 'inventory_type' => 'none']);
    expect($item->fresh()->isRunningLow())->toBeFalse();
});
