<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Laravel\Sanctum\Sanctum;

/*
| restrictOnDelete() foreign keys correctly protect historical/related records at the
| database level - but a bare $model->delete() let that QueryException surface as an
| unhandled 500. DeletesSafely turns it into a RecordInUseException, and each back-office
| controller flashes it back instead of crashing.
*/

test('deleting a category referenced by a paid order flashes an error instead of crashing', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $manager, 'john');
    posAddItem($ticket, $item, 1);

    Sanctum::actingAs($manager, ['*']);
    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]],
    ])->assertOk();

    $this->actingAs($manager)
        ->delete("/categories/{$item->category_id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($item->fresh())->not->toBeNull();
});

test('deleting a modifier referenced by a paid order flashes an error instead of crashing', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $group = ModifierGroup::create(['name' => 'Extras']);
    $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Cheese', 'status' => 'active']);
    $item = posItem('Burger', 100, quantity: 10);
    $item->modifiers()->attach($modifier->id, ['status' => 'active', 'display_order' => 1]);
    $ticket = posTicket($shift, $manager, 'john');

    Sanctum::actingAs($manager, ['*']);
    $this->postJson("/api/v1/tickets/{$ticket->id}/items", [
        'item_id' => $item->id, 'quantity' => 1, 'modifier_ids' => [$modifier->id],
    ])->assertStatus(201);
    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]],
    ])->assertOk();

    $this->actingAs($manager)
        ->delete("/modifiers/{$modifier->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($modifier->fresh())->not->toBeNull();
});

test('deleting an item referenced by a paid order flashes an error instead of crashing', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $item = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $manager, 'john');
    posAddItem($ticket, $item, 1);

    Sanctum::actingAs($manager, ['*']);
    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]],
    ])->assertOk();

    $this->actingAs($manager)
        ->delete("/items/{$item->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Item::find($item->id))->not->toBeNull();
});

test('deleting an ingredient used in a recipe flashes an error instead of crashing', function () {
    $manager = posUser('manager');
    $group = IngredientGroup::create(['name' => 'Produce']);
    $ingredient = Ingredient::create([
        'ingredient_group_id' => $group->id,
        'name' => 'Lettuce',
        'unit' => 'piece',
        'quantity' => 100,
        'reserved_quantity' => 0,
    ]);
    $item = Item::create([
        'category_id' => Category::firstOrCreate(['name' => 'Test Category'], ['status' => 'active', 'is_visible_to_pos' => true])->id,
        'name' => 'Salad',
        'base_price' => 50,
        'cost_price' => 0,
        'inventory_type' => 'recipe',
        'status' => 'available',
    ]);
    $item->ingredients()->attach($ingredient->id, ['quantity_required' => 1, 'unit' => 'piece']);

    $this->actingAs($manager)
        ->delete("/ingredients/{$ingredient->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Ingredient::find($ingredient->id))->not->toBeNull();
});

test('deleting an ingredient group that still has ingredients flashes an error instead of crashing', function () {
    $manager = posUser('manager');
    $group = IngredientGroup::create(['name' => 'Produce']);
    Ingredient::create([
        'ingredient_group_id' => $group->id,
        'name' => 'Tomato',
        'unit' => 'piece',
        'quantity' => 50,
        'reserved_quantity' => 0,
    ]);

    $this->actingAs($manager)
        ->delete("/ingredient-groups/{$group->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(IngredientGroup::find($group->id))->not->toBeNull();
});

test('a category with no sales history still deletes cleanly', function () {
    $manager = posUser('manager');
    $category = Category::create(['name' => 'Unused Category', 'status' => 'active', 'is_visible_to_pos' => true]);

    $this->actingAs($manager)
        ->delete("/categories/{$category->id}")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Category::find($category->id))->toBeNull();
});
