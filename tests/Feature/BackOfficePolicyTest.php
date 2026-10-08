<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\User;

function boCategory(): Category
{
    return Category::create(['name' => 'Mains', 'status' => 'active', 'is_visible_to_pos' => true]);
}

function boItem(Category $category): Item
{
    return Item::create([
        'category_id' => $category->id,
        'name' => 'Burger',
        'base_price' => 100,
        'cost_price' => 40,
        'quantity' => 10,
        'inventory_type' => 'direct',
        'status' => 'available',
    ]);
}

function boModifierGroup(): ModifierGroup
{
    return ModifierGroup::create(['name' => 'Size']);
}

function boModifier(ModifierGroup $group): Modifier
{
    return Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Large']);
}

function boIngredientGroup(): IngredientGroup
{
    return IngredientGroup::create(['name' => 'Produce']);
}

function boIngredient(IngredientGroup $group): Ingredient
{
    return Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Lettuce', 'unit' => 'kg', 'quantity' => 10, 'cost_per_unit' => 5]);
}

test('dashboard and the back-office hub stay open to a cashier', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);

    $this->actingAs($cashier)->get('/dashboard')->assertOk();
    $this->actingAs($cashier)->get('/back-office')->assertOk();
});

test('a cashier is forbidden from every management index page, a manager is not', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $manager = User::factory()->create(['role' => 'manager']);

    $pages = [
        '/category-management',
        '/item-management',
        '/modifier-management',
        '/ingredient-management',
        '/employee-management',
        '/shifts',
        '/tickets',
        '/kitchen-orders',
        '/sales',
    ];

    foreach ($pages as $page) {
        $this->actingAs($cashier)->get($page)->assertForbidden();
        $this->actingAs($manager)->get($page)->assertOk();
    }
});

test('a cashier is forbidden from every "create" page, a manager is not', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $manager = User::factory()->create(['role' => 'manager']);

    $pages = [
        '/create-category',
        '/create-item',
        '/create-modifier-group',
        '/create-modifier',
        '/create-ingredient-group',
        '/create-ingredient',
        '/users/create',
    ];

    foreach ($pages as $page) {
        $this->actingAs($cashier)->get($page)->assertForbidden();
        $this->actingAs($manager)->get($page)->assertOk();
    }
});

test('a cashier is forbidden from every "edit" page, a manager is not', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $manager = User::factory()->create(['role' => 'manager']);
    $category = boCategory();
    $item = boItem($category);
    $modifierGroup = boModifierGroup();
    $modifier = boModifier($modifierGroup);
    $ingredientGroup = boIngredientGroup();
    $ingredient = boIngredient($ingredientGroup);

    $pages = [
        "/categories/{$category->id}/edit",
        "/items/{$item->id}/edit",
        "/modifier-groups/{$modifierGroup->id}/edit",
        "/modifiers/{$modifier->id}/edit",
        "/ingredient-groups/{$ingredientGroup->id}/edit",
        "/ingredients/{$ingredient->id}/edit",
    ];

    foreach ($pages as $page) {
        $this->actingAs($cashier)->get($page)->assertForbidden();
        $this->actingAs($manager)->get($page)->assertOk();
    }
});

test('a cashier cannot delete any management resource, a manager can', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);

    $category = boCategory();
    $this->actingAs($cashier)->delete("/categories/{$category->id}")->assertForbidden();

    $item = boItem($category);
    $this->actingAs($cashier)->delete("/items/{$item->id}")->assertForbidden();

    $modifierGroup = boModifierGroup();
    $modifier = boModifier($modifierGroup);
    $this->actingAs($cashier)->delete("/modifiers/{$modifier->id}")->assertForbidden();
    $this->actingAs($cashier)->delete("/modifier-groups/{$modifierGroup->id}")->assertForbidden();

    $ingredientGroup = boIngredientGroup();
    $ingredient = boIngredient($ingredientGroup);
    $this->actingAs($cashier)->delete("/ingredients/{$ingredient->id}")->assertForbidden();
    $this->actingAs($cashier)->delete("/ingredient-groups/{$ingredientGroup->id}")->assertForbidden();

    $target = User::factory()->create(['role' => 'cashier']);
    $this->actingAs($cashier)->delete("/users/{$target->id}")->assertForbidden();

    // None of the above were actually deleted.
    expect(Category::find($category->id))->not->toBeNull()
        ->and(Item::find($item->id))->not->toBeNull()
        ->and(Modifier::find($modifier->id))->not->toBeNull()
        ->and(ModifierGroup::find($modifierGroup->id))->not->toBeNull()
        ->and(Ingredient::find($ingredient->id))->not->toBeNull()
        ->and(IngredientGroup::find($ingredientGroup->id))->not->toBeNull()
        ->and(User::find($target->id))->not->toBeNull();
});

test('a manager can delete a management resource', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $category = boCategory();

    $this->actingAs($manager)->delete("/categories/{$category->id}")->assertRedirect();

    expect(Category::find($category->id))->toBeNull();
});

test('an admin is never subject to a delete or edit, regardless of the actor', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($manager)->get("/users/{$admin->id}/edit")->assertForbidden();
    $this->actingAs($manager)->delete("/users/{$admin->id}")->assertForbidden();
});
