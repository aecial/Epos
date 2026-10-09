<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\User;

test('manager can create raw materials and assign them to a recipe item', function () {
    $this->actingAs(User::factory()->create(['role' => 'manager']));

    $groupResponse = $this->postJson('/ingredient-groups', [
        'name' => 'Raw Materials',
        'status' => 'active',
    ]);

    $groupResponse->assertSuccessful();
    $group = $groupResponse->json();

    $ingredientResponse = $this->postJson('/ingredients', [
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
        ->assertJsonPath('ingredients.0.pivot.quantity_required', '1.000');

    $this->get("/items/{$item->id}/recipe")
        ->assertSuccessful()
        ->assertJsonPath('ingredients.0.name', 'Itik');
});

test('cashier cannot manage raw materials or recipes', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);

    // A cashier is signed out of the back office before any policy runs.
    $this->actingAs($cashier)->post('/ingredient-groups', ['name' => 'Raw Materials'])
        ->assertRedirect('/login');

    $this->actingAs($cashier)->post('/ingredients', [
        'ingredient_group_id' => 1,
        'name' => 'Itik',
        'unit' => 'piece',
    ])->assertRedirect('/login');

    expect(IngredientGroup::count())->toBe(0)
        ->and(Ingredient::count())->toBe(0);
});
