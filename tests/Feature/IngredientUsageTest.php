<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Ticket;
use App\Models\TicketItemIngredient;
use App\Services\RefundService;

/*
| ticket_item_ingredients: what a paid recipe line took from each ingredient, recorded when stock
| is deducted at payment. Usage reports read it, and approving a refund returns exactly that -
| so editing a recipe or an ingredient's cost later never changes history.
*/

/** Sisig: 0.2 kg pork (₱350/kg) + 1 egg (₱10/piece). Pork 10 kg and eggs 30 in stock. */
function usageSisig(): Item
{
    $group = IngredientGroup::create(['name' => 'Meats']);
    $pork = Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Pork', 'unit' => 'kg', 'quantity' => 10, 'cost_per_unit' => 350]);
    $egg = Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Egg', 'unit' => 'piece', 'quantity' => 30, 'cost_per_unit' => 10]);
    $category = Category::firstOrCreate(['name' => 'Mains'], ['status' => 'active', 'is_visible_to_pos' => true]);

    $sisig = Item::create(['category_id' => $category->id, 'name' => 'Sisig', 'base_price' => 180, 'cost_price' => 0, 'inventory_type' => 'recipe', 'status' => 'available']);
    $sisig->ingredients()->attach([
        $pork->id => ['quantity_required' => 0.2, 'unit' => 'kg'],
        $egg->id => ['quantity_required' => 1, 'unit' => 'piece'],
    ]);

    return $sisig;
}

function usagePaySisig(int $servings): Ticket
{
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    posAddItem($ticket, usageSisig(), $servings);

    return posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 180 * $servings, 'tendered_amount' => 180 * $servings]]);
}

test('paying a recipe item records what each ingredient gave up, with its unit and cost', function () {
    $ticket = usagePaySisig(3);
    $line = $ticket->items()->firstOrFail();

    $usage = $line->ingredientUsage()->with('ingredient')->get()->keyBy('ingredient.name');

    expect($usage)->toHaveCount(2)
        ->and((float) $usage['Pork']->quantity_used)->toBe(0.6)
        ->and($usage['Pork']->unit)->toBe('kg')
        ->and((float) $usage['Pork']->cost_per_unit)->toBe(350.0)
        ->and((float) $usage['Egg']->quantity_used)->toBe(3.0)
        ->and($usage['Egg']->unit)->toBe('piece')
        ->and((float) Ingredient::firstWhere('name', 'Pork')->quantity)->toBe(9.4);
});

test('direct and untracked items record no ingredient usage', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');
    posAddItem($ticket, posItem('Softdrinks', 35));
    posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 35, 'tendered_amount' => 35]]);

    expect(TicketItemIngredient::count())->toBe(0);
});

test('editing the recipe or an ingredient cost after payment leaves recorded usage alone', function () {
    $line = usagePaySisig(2)->items()->firstOrFail();
    $pork = Ingredient::firstWhere('name', 'Pork');

    Item::firstWhere('name', 'Sisig')->ingredients()->updateExistingPivot($pork->id, ['quantity_required' => 0.5]);
    $pork->update(['cost_per_unit' => 999]);

    $recorded = $line->ingredientUsage()->where('ingredient_id', $pork->id)->firstOrFail();

    expect((float) $recorded->quantity_used)->toBe(0.4)
        ->and((float) $recorded->cost_per_unit)->toBe(350.0);
});

test('approving a refund returns what the sale took, even after the recipe changed', function () {
    $manager = posUser('manager');
    $manager->update(['passcode' => '2468']);
    $ticket = usagePaySisig(2);
    $line = $ticket->items()->firstOrFail();
    $pork = Ingredient::firstWhere('name', 'Pork');
    $egg = Ingredient::firstWhere('name', 'Egg');

    expect((float) $pork->quantity)->toBe(9.6)->and((float) $egg->quantity)->toBe(28.0);

    // The recipe now says 0.5 kg; the sale took 0.2 kg per serving, so 1 serving returns 0.2 kg.
    Item::firstWhere('name', 'Sisig')->ingredients()->updateExistingPivot($pork->id, ['quantity_required' => 0.5]);

    $refund = app(RefundService::class)->RequestRefund($ticket, $ticket->charges()->firstOrFail(), $manager, [
        ['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 180],
    ]);
    app(RefundService::class)->ApproveRefund($refund, $manager, '2468');

    expect((float) $pork->fresh()->quantity)->toBe(9.8)
        ->and((float) $egg->fresh()->quantity)->toBe(29.0);
});

test('a rejected refund returns nothing', function () {
    $manager = posUser('manager');
    $manager->update(['passcode' => '2468']);
    $ticket = usagePaySisig(2);

    $refund = app(RefundService::class)->RequestRefund($ticket, $ticket->charges()->firstOrFail(), $manager, [
        ['ticket_item_id' => $ticket->items()->firstOrFail()->id, 'quantity' => 1, 'amount' => 180],
    ]);
    app(RefundService::class)->RejectRefund($refund, $manager, '2468');

    expect((float) Ingredient::firstWhere('name', 'Pork')->quantity)->toBe(9.6);
});
