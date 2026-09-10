<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Item;
use App\Models\User;

test('manager can create raw materials and assign them to a recipe item', function () {
    $this->actingAs(User::factory()->create(['role' => 'manager']));

    $groupResponse = $this->post('/ingredient-groups', [
        'name' => 'Raw Materials',
        'status' => 'active',
    ]);

    $groupResponse->assertSuccessful();
    $group = $groupResponse->json();

    $ingredientResponse = $this->post('/ingredients', [
        'ingredient_group_id' => $group['id'],
        'name' => 'Itik',
        'unit' => 'piece',
        'quantity' => 10,
        'cost_per_unit' => 150,
        'status' => 'active',
    ]);

    $ingredientResponse->assertSuccessful();
    $ingredient = Ingredient::query()->where('name', 'Itik')->firstOrFail();

    $category = Category::create([
        'name' => 'Main Dishes',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);

    $item = Item::create([
        'category_id' => $category->id,
        'name' => 'Fried Itik',
        'base_price' => 300,
        'cost_price' => 150,
        'quantity' => 0,
        'status' => 'available',
        'inventory_type' => 'recipe',
    ]);

    $this->put("/items/{$item->id}/recipe", [
        'ingredients' => [[
            'ingredient_id' => $ingredient->id,
            'quantity_required' => 1,
            'unit' => 'piece',
        ]],
    ])->assertSuccessful()
        ->assertJsonPath('ingredients.0.id', $ingredient->id)
        ->assertJsonPath('ingredients.0.pivot.quantity_required', 1);

    $this->get("/items/{$item->id}/recipe")
        ->assertSuccessful()
        ->assertJsonPath('ingredients.0.name', 'Itik');
});

test('cashier cannot manage raw materials or recipes', function () {
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    $this->post('/ingredient-groups', ['name' => 'Raw Materials'])
        ->assertForbidden();

    $this->post('/ingredients', [
        'ingredient_group_id' => 1,
        'name' => 'Itik',
        'unit' => 'piece',
    ])->assertForbidden();
});
