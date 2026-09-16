<?php

use App\Exceptions\InsufficientInventoryException;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Services\InventoryService;
use App\Services\ItemRecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createRecipeItem(string $name, Category $category, Ingredient $itik): Item
{
    $item = Item::create([
        'category_id' => $category->id,
        'name' => $name,
        'base_price' => 300,
        'cost_price' => 100,
        'quantity' => 0,
        'status' => 'available',
        'inventory_type' => 'recipe',
    ]);

    (new ItemRecipeService)->ReplaceItemRecipe($item, [[
        'ingredient_id' => $itik->id,
        'quantity_required' => 1,
        'unit' => 'piece',
    ]]);

    return $item->fresh('ingredients');
}

beforeEach(function () {
    $this->category = Category::create([
        'name' => 'Main Dishes',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);

    $group = IngredientGroup::create([
        'name' => 'Raw Materials',
        'status' => 'active',
    ]);

    $this->itik = Ingredient::create([
        'ingredient_group_id' => $group->id,
        'name' => 'Itik',
        'unit' => 'piece',
        'quantity' => 10,
        'reserved_quantity' => 0,
        'cost_per_unit' => 150,
        'status' => 'active',
    ]);

    $this->inventory = new InventoryService;
});

test('fried itik, adobong itik, and sisig itik share the same raw material stock', function () {
    $friedItik = createRecipeItem('Fried Itik', $this->category, $this->itik);
    $adobongItik = createRecipeItem('Adobong Itik', $this->category, $this->itik);
    $sisigItik = createRecipeItem('Sisig Itik', $this->category, $this->itik);

    $this->inventory->ReserveItem($friedItik, 1);
    $this->inventory->ReserveItem($adobongItik, 1);
    $this->inventory->ReserveItem($sisigItik, 1);

    $this->itik->refresh();

    expect($this->itik->quantity)->toBe('10.000')
        ->and($this->itik->reserved_quantity)->toBe('3.000')
        ->and($this->inventory->AvailableForItem($friedItik))->toBe(7.0);
});

test('payment deduction removes the shared itik stock and clears its reservation', function () {
    $friedItik = createRecipeItem('Fried Itik', $this->category, $this->itik);
    $adobongItik = createRecipeItem('Adobong Itik', $this->category, $this->itik);
    $sisigItik = createRecipeItem('Sisig Itik', $this->category, $this->itik);

    $this->inventory->ReserveItem($friedItik, 1);
    $this->inventory->ReserveItem($adobongItik, 1);
    $this->inventory->ReserveItem($sisigItik, 1);

    $this->inventory->DeductItem($friedItik, 1);
    $this->inventory->DeductItem($adobongItik, 1);
    $this->inventory->DeductItem($sisigItik, 1);

    $this->itik->refresh();

    expect($this->itik->quantity)->toBe('7.000')
        ->and($this->itik->reserved_quantity)->toBe('0.000');
});

test('insufficient shared stock rolls back every reservation', function () {
    $this->itik->update(['quantity' => 2]);

    $friedItik = createRecipeItem('Fried Itik', $this->category, $this->itik);
    $adobongItik = createRecipeItem('Adobong Itik', $this->category, $this->itik);
    $sisigItik = createRecipeItem('Sisig Itik', $this->category, $this->itik);

    $this->inventory->ReserveItem($friedItik, 1);
    $this->inventory->ReserveItem($adobongItik, 1);

    expect(fn () => $this->inventory->ReserveItem($sisigItik, 1))
        ->toThrow(InsufficientInventoryException::class);

    $this->itik->refresh();

    expect($this->itik->quantity)->toBe('2.000')
        ->and($this->itik->reserved_quantity)->toBe('2.000');
});

test('cancelling recipe items releases their shared reservation', function () {
    $friedItik = createRecipeItem('Fried Itik', $this->category, $this->itik);
    $adobongItik = createRecipeItem('Adobong Itik', $this->category, $this->itik);

    $this->inventory->ReserveItem($friedItik, 1);
    $this->inventory->ReserveItem($adobongItik, 1);
    $this->inventory->ReleaseItem($friedItik, 1);
    $this->inventory->ReleaseItem($adobongItik, 1);

    $this->itik->refresh();

    expect($this->itik->quantity)->toBe('10.000')
        ->and($this->itik->reserved_quantity)->toBe('0.000');
});

test('fractional recipe quantities reserve the correct raw material amount', function () {
    $item = Item::create([
        'category_id' => $this->category->id,
        'name' => 'Half Itik Dish',
        'base_price' => 200,
        'cost_price' => 80,
        'quantity' => 0,
        'status' => 'available',
        'inventory_type' => 'recipe',
    ]);

    (new ItemRecipeService)->ReplaceItemRecipe($item, [[
        'ingredient_id' => $this->itik->id,
        'quantity_required' => 0.5,
        'unit' => 'piece',
    ]]);

    $this->inventory->ReserveItem($item, 3);
    $this->itik->refresh();

    expect($this->itik->reserved_quantity)->toBe('1.500')
        ->and($this->inventory->AvailableForItem($item))->toBe(17.0);
});

test('direct items continue using their own quantity inventory', function () {
    $item = Item::create([
        'category_id' => $this->category->id,
        'name' => 'Bottled Water',
        'base_price' => 30,
        'cost_price' => 10,
        'quantity' => 20,
        'reserved_quantity' => 0,
        'status' => 'available',
        'inventory_type' => 'direct',
    ]);

    $this->inventory->ReserveItem($item, 2);
    $this->inventory->DeductItem($item, 2);
    $item->refresh();
    $this->itik->refresh();

    expect($item->quantity)->toBe(18)
        ->and($item->reserved_quantity)->toBe(0)
        ->and($this->itik->quantity)->toBe('10.000')
        ->and($this->itik->reserved_quantity)->toBe('0.000');
});
