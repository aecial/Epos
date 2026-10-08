<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;

/*
| The per-unit cost snapshotted onto a ticket line when it's added (ticket_items.item_cost_price),
| which profit reporting reads. A recipe item's cost is its ingredients - its own cost_price isn't
| maintained - and a snapshot never moves once taken, so later price changes don't rewrite history.
*/

/** A recipe item using 1 Itik (₱180/piece) and 0.25 kg of rice (₱60/kg): ₱195 per serving. */
function costedRecipeItem(): Item
{
    $group = IngredientGroup::create(['name' => 'Kitchen']);
    $itik = Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Itik', 'unit' => 'piece', 'quantity' => 50, 'cost_per_unit' => 180]);
    $rice = Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Rice', 'unit' => 'kg', 'quantity' => 20, 'cost_per_unit' => 60]);
    $category = Category::firstOrCreate(['name' => 'Itik'], ['status' => 'active', 'is_visible_to_pos' => true]);

    $item = Item::create([
        'category_id' => $category->id,
        'name' => 'Fried Itik',
        'base_price' => 295,
        'cost_price' => 0,
        'inventory_type' => 'recipe',
        'status' => 'available',
    ]);
    $item->ingredients()->attach([
        $itik->id => ['quantity_required' => 1, 'unit' => 'piece'],
        $rice->id => ['quantity_required' => 0.25, 'unit' => 'kg'],
    ]);

    return $item;
}

test('a recipe item\'s line records what its ingredients cost, not the item\'s unused cost_price', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');

    $line = posAddItem($ticket, costedRecipeItem(), 2);

    expect((float) $line->item_cost_price)->toBe(195.0);
});

test('a direct item\'s line records its cost_price, as before', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $softdrinks = posItem('Softdrinks', 30);
    $softdrinks->update(['cost_price' => 18]);

    $line = posAddItem($ticket, $softdrinks);

    expect((float) $line->item_cost_price)->toBe(18.0);
});

test('a line keeps the cost it was sold at when an ingredient\'s cost changes later', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    $item = costedRecipeItem();
    $line = posAddItem($ticket, $item);

    Ingredient::where('name', 'Itik')->update(['cost_per_unit' => 200]);
    $later = posAddItem($ticket->fresh(), $item);

    expect((float) $line->fresh()->item_cost_price)->toBe(195.0)
        ->and((float) $later->item_cost_price)->toBe(215.0);
});
