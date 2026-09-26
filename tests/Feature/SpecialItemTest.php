<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

/*
| Special items live in a category of type 'special'. Two kinds:
|   Fee item    (entry_mode 'price')      - the cashier types the amount
|   Custom item (entry_mode 'name_price') - the cashier types the name and the amount
| They never touch stock, and the ticket discount applies to them like any other line.
*/

function specialCategory(string $name = 'Specials'): Category
{
    return Category::create(['name' => $name, 'type' => 'special', 'status' => 'active', 'is_visible_to_pos' => true]);
}

/** A Fee item: the cashier types the amount. */
function feeItem(string $name = 'Delivery Fee', float $default = 0): Item
{
    return Item::create([
        'category_id' => specialCategory($name.' Category')->id,
        'name' => $name,
        'base_price' => $default,
        'inventory_type' => 'none',
        'entry_mode' => 'price',
        'status' => 'available',
    ]);
}

/** A Custom item: the cashier types the name and the amount. */
function customItem(string $name = 'Custom Item'): Item
{
    return Item::create([
        'category_id' => specialCategory($name.' Category')->id,
        'name' => $name,
        'base_price' => 0,
        'inventory_type' => 'none',
        'entry_mode' => 'name_price',
        'status' => 'available',
    ]);
}

function openTicketFor(User $cashier): Ticket
{
    return posTicket(posOpenShift($cashier), $cashier, 'john');
}

// ---------------------------------------------------------------- write path (back office)

test('an item created in a special category is forced to no inventory and no cost', function () {
    $category = specialCategory();

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/items', [
            'category_id' => $category->id,
            'name' => 'Delivery Fee',
            'base_price' => 50,
            'cost_price' => 20,
            'quantity' => 10,
            'inventory_type' => 'none',
            'entry_mode' => 'price',
            'status' => 'available',
        ])
        ->assertRedirectToRoute('item-management');

    $item = Item::where('name', 'Delivery Fee')->firstOrFail();

    expect($item->inventory_type)->toBe('none')
        ->and($item->entry_mode)->toBe('price')
        ->and((int) $item->quantity)->toBe(0)
        ->and((float) $item->cost_price)->toBe(0.0);
});

test('a special item omitting inventory_type still ends up as none rather than the direct default', function () {
    $category = specialCategory();

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/items', ['category_id' => $category->id, 'name' => 'Service Charge', 'base_price' => 0, 'entry_mode' => 'price'])
        ->assertRedirectToRoute('item-management');

    expect(Item::where('name', 'Service Charge')->firstOrFail()->inventory_type)->toBe('none');
});

test('special items cannot have a recipe, modifiers or tracked inventory', function () {
    $category = specialCategory();
    $group = IngredientGroup::create(['name' => 'Raw', 'status' => 'active']);
    $ingredient = Ingredient::create(['ingredient_group_id' => $group->id, 'name' => 'Oil', 'unit' => 'liter', 'quantity' => 5, 'status' => 'active']);
    $modifier = Modifier::create(['name' => 'Extra', 'status' => 'active']);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/items', [
            'category_id' => $category->id,
            'name' => 'Bad Fee',
            'base_price' => 10,
            'inventory_type' => 'recipe',
            'entry_mode' => 'price',
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity_required' => 1, 'unit' => 'liter']],
            'modifiers' => [['modifier_id' => $modifier->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['inventory_type', 'ingredients', 'modifiers']);

    expect(Item::where('name', 'Bad Fee')->exists())->toBeFalse();
});

test('only special categories may have items where the cashier enters the price or name', function () {
    $menu = Category::create(['name' => 'Mains', 'status' => 'active', 'is_visible_to_pos' => true]);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/items', ['category_id' => $menu->id, 'name' => 'Sneaky', 'base_price' => 100, 'entry_mode' => 'price'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('entry_mode');
});

test('moving a menu item into a special category clears its recipe and modifiers', function () {
    $menu = Category::create(['name' => 'Mains', 'status' => 'active', 'is_visible_to_pos' => true]);
    $special = specialCategory();
    $modifier = Modifier::create(['name' => 'Extra', 'status' => 'active']);
    $item = posItem('Rice', 40);
    $item->modifiers()->attach($modifier->id, ['price_modifier' => 5]);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->patch("/items/{$item->id}", ['category_id' => $special->id, 'entry_mode' => 'price'])
        ->assertRedirectToRoute('item-management');

    $item->refresh();

    expect($item->inventory_type)->toBe('none')
        ->and($item->modifiers()->count())->toBe(0);
});

test('a category type can be chosen on create and is locked once it has items', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->post('/categories', ['name' => 'Extras', 'type' => 'special'])
        ->assertRedirectToRoute('category-management');

    $category = Category::where('name', 'Extras')->firstOrFail();
    expect($category->type)->toBe('special');

    // Empty categories can still change type; populated ones cannot.
    $this->actingAs($admin)->patch("/categories/{$category->id}", ['type' => 'menu'])->assertRedirect();
    expect($category->fresh()->type)->toBe('menu');

    posItem('Rice', 40); // 'Test Category', a menu category with an item
    $populated = Category::where('name', 'Test Category')->firstOrFail();

    $this->actingAs($admin)
        ->patchJson("/categories/{$populated->id}", ['type' => 'special'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    // Re-sending the same type is not a change.
    $this->actingAs($admin)->patch("/categories/{$populated->id}", ['type' => 'menu', 'name' => 'Renamed'])->assertRedirect();
    expect($populated->fresh()->name)->toBe('Renamed');
});

// ---------------------------------------------------------------- adding lines (POS API)

test('a Fee item takes the cashier-typed amount and is snapshotted as a fee', function () {
    $cashier = posUser();
    $ticket = openTicketFor($cashier);
    $fee = feeItem(default: 30);
    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $fee->id, 'quantity' => 1, 'unit_price' => 50])
        ->assertCreated()
        ->assertJsonPath('data.items.0.item_name', 'Delivery Fee')
        ->assertJsonPath('data.items.0.line_type', 'fee')
        ->assertJsonPath('data.subtotal', 50)
        ->assertJsonPath('data.total', 50);

    // The typed amount, not the 30 default.
    expect((float) TicketItem::firstOrFail()->unit_price)->toBe(50.0);
});

test('a Custom item takes the typed name and amount and is snapshotted as custom', function () {
    $cashier = posUser();
    $ticket = openTicketFor($cashier);
    $custom = customItem();
    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", [
        'item_id' => $custom->id,
        'quantity' => 2,
        'unit_price' => 110.5,
        'custom_name' => 'Lechon Paksiw',
        'notes' => 'no onions',
    ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.item_name', 'Lechon Paksiw')
        ->assertJsonPath('data.items.0.line_type', 'custom')
        ->assertJsonPath('data.items.0.notes', 'no onions')
        ->assertJsonPath('data.total', 221);

    // Renaming the template afterwards never rewrites the line.
    $custom->update(['name' => 'Something else']);
    expect(TicketItem::firstOrFail()->item_name)->toBe('Lechon Paksiw');
});

test('missing or forbidden price/name fields are rejected per entry mode', function (string $kind, array $extra, array $errors) {
    $cashier = posUser();
    $ticket = openTicketFor($cashier);
    $item = match ($kind) {
        'fee' => feeItem(),
        'custom' => customItem(),
        'menu' => posItem('Burger', 100),
    };
    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $item->id, 'quantity' => 1, ...$extra])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    expect(TicketItem::count())->toBe(0);
})->with([
    'fee without amount' => ['fee', [], ['unit_price']],
    'fee with a name' => ['fee', ['unit_price' => 10, 'custom_name' => 'Nope'], ['custom_name']],
    'custom without name' => ['custom', ['unit_price' => 10], ['custom_name']],
    'custom without amount' => ['custom', ['custom_name' => 'Cake'], ['unit_price']],
    'custom with nothing' => ['custom', [], ['unit_price', 'custom_name']],
    'menu item with a price override' => ['menu', ['unit_price' => 1], ['unit_price']],
    'menu item with a name override' => ['menu', ['custom_name' => 'Free food'], ['custom_name']],
    'zero amount' => ['fee', ['unit_price' => 0], ['unit_price']],
    'negative amount' => ['fee', ['unit_price' => -5], ['unit_price']],
    'three decimals' => ['fee', ['unit_price' => 10.999], ['unit_price']],
    'absurd amount' => ['fee', ['unit_price' => 5000000], ['unit_price']],
    'multi-line name' => ['custom', ['unit_price' => 10, 'custom_name' => "a\nb"], ['custom_name']],
    'name too long' => ['custom', ['unit_price' => 10, 'custom_name' => str_repeat('x', 101)], ['custom_name']],
]);

test('a fixed amount fee in a special category needs no cashier input and is still a fee', function () {
    $cashier = posUser();
    $ticket = openTicketFor($cashier);
    $packaging = Item::create([
        'category_id' => specialCategory()->id,
        'name' => 'Packaging',
        'base_price' => 10,
        'inventory_type' => 'none',
        'status' => 'available',
    ]);
    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $packaging->id, 'quantity' => 1])
        ->assertCreated()
        ->assertJsonPath('data.items.0.line_type', 'fee')
        ->assertJsonPath('data.total', 10);

    // ...and, like any fixed item, rejects an override.
    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $packaging->id, 'quantity' => 1, 'unit_price' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('unit_price');
});

test('a regular menu line is still an item and sells at its base price', function () {
    $cashier = posUser();
    $ticket = openTicketFor($cashier);
    $burger = posItem('Burger', 100);
    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $burger->id, 'quantity' => 2])
        ->assertCreated()
        ->assertJsonPath('data.items.0.line_type', 'item')
        ->assertJsonPath('data.total', 200);
});

test('the service refuses a price override on a normal item even when called directly', function () {
    $cashier = posUser();
    $ticket = openTicketFor($cashier);

    expect(fn () => app(TicketService::class)->AddItem($ticket, posItem('Burger', 100)->fresh(), 1, [], null, 1.0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(TicketService::class)->AddItem($ticket, feeItem()->fresh(), 1))
        ->toThrow(InvalidArgumentException::class);

    expect(TicketItem::count())->toBe(0);
});

// ---------------------------------------------------------------- money and stock

test('special lines are discounted, paid, receipted and never touch stock', function () {
    $cashier = posUser();
    $ticket = openTicketFor($cashier);
    $burger = posItem('Burger', 100, quantity: 10);
    $fee = feeItem();
    $custom = customItem();
    Sanctum::actingAs($cashier);

    $add = fn (array $body) => $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['quantity' => 1, ...$body])->assertCreated();
    $add(['item_id' => $burger->id]);
    $add(['item_id' => $fee->id, 'unit_price' => 50]);
    $add(['item_id' => $custom->id, 'unit_price' => 250, 'custom_name' => 'Lechon Paksiw']);

    // 100 + 50 + 250 = 400; the 10% discount applies to every line, fees included.
    $this->patchJson("/api/v1/tickets/{$ticket->id}/discount", ['discount_percent' => 10])
        ->assertOk()
        ->assertJsonPath('data.subtotal', 400)
        ->assertJsonPath('data.total', 360);

    // Only the burger reserved stock.
    expect($burger->fresh()->reserved_quantity)->toBe(1)
        ->and($fee->fresh()->reserved_quantity)->toBe(0)
        ->and($custom->fresh()->reserved_quantity)->toBe(0);

    $response = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200],
            ['payment_method' => 'gcash', 'amount' => 160, 'payment_reference' => 'GC-SPECIAL'],
        ],
    ])->assertOk();

    // The receipt lists every line, with the typed name and its line type.
    $items = collect($response->json('data.charges.0.receipt.payload.items'));
    expect($items->pluck('name')->all())->toBe(['Burger', 'Delivery Fee', 'Lechon Paksiw'])
        ->and($items->pluck('line_type')->all())->toBe(['item', 'fee', 'custom'])
        ->and($items->pluck('line_total')->sum())->toEqual(400);

    expect($burger->fresh()->quantity)->toBe(9)
        ->and($burger->fresh()->reserved_quantity)->toBe(0)
        ->and($fee->fresh()->quantity)->toBe(0)
        ->and($custom->fresh()->quantity)->toBe(0)
        ->and($ticket->fresh()->status)->toBe('paid');
});

test('a special line can be voided only with a manager passcode and never moves stock', function () {
    $cashier = posUser();
    $manager = User::factory()->create(['role' => 'manager', 'passcode' => '1234']);
    $ticket = openTicketFor($cashier);
    $fee = feeItem();
    Sanctum::actingAs($cashier);

    $lineId = $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $fee->id, 'quantity' => 1, 'unit_price' => 50])
        ->assertCreated()
        ->json('meta.ticket_item_id');

    $this->deleteJson("/api/v1/tickets/{$ticket->id}/items/{$lineId}", ['approver_id' => $manager->id, 'passcode' => '9999'])
        ->assertForbidden();

    $this->deleteJson("/api/v1/tickets/{$ticket->id}/items/{$lineId}", ['approver_id' => $manager->id, 'passcode' => '1234'])
        ->assertOk()
        ->assertJsonPath('data.total', 0);

    expect($fee->fresh()->reserved_quantity)->toBe(0);
});

test('special lines survive a merge with their type and amount intact', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $target = posTicket($shift, $cashier, 'john');
    $source = posTicket($shift, $cashier, 'maria');
    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$source->id}/items", ['item_id' => customItem()->id, 'quantity' => 1, 'unit_price' => 300, 'custom_name' => 'Birthday Cake'])
        ->assertCreated();

    $this->postJson("/api/v1/tickets/{$target->id}/merge", ['merge_from_ticket_ids' => [$source->id]])
        ->assertOk()
        ->assertJsonPath('data.items.0.item_name', 'Birthday Cake')
        ->assertJsonPath('data.items.0.line_type', 'custom')
        ->assertJsonPath('data.total', 300);
});

// ---------------------------------------------------------------- menu API

test('the POS menu tells the app how each item is priced and which category type it is in', function () {
    $cashier = posUser();
    $burger = posItem('Burger', 100);
    $fee = feeItem();
    $custom = customItem();
    Sanctum::actingAs($cashier);

    $rows = collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$burger->id])->toMatchArray(['entry_mode' => 'fixed'])
        ->and($rows[$burger->id]['category']['type'])->toBe('menu')
        ->and($rows[$fee->id])->toMatchArray(['entry_mode' => 'price', 'available_stock' => null])
        ->and($rows[$fee->id]['category']['type'])->toBe('special')
        ->and($rows[$custom->id])->toMatchArray(['entry_mode' => 'name_price', 'available_stock' => null])
        ->and($rows[$custom->id])->not->toHaveKey('cost_price');
});

test('refunding a special line restores no stock', function () {
    $cashier = posUser();
    $manager = User::factory()->create(['role' => 'manager', 'passcode' => '1234']);
    $ticket = openTicketFor($cashier);
    $fee = feeItem();
    Sanctum::actingAs($cashier);

    $lineId = $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $fee->id, 'quantity' => 1, 'unit_price' => 50])
        ->assertCreated()
        ->json('meta.ticket_item_id');

    $chargeId = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", ['charges' => [['payment_method' => 'cash', 'amount' => 50]]])
        ->assertOk()
        ->json('data.charges.0.id');

    $refundId = $this->postJson('/api/v1/refunds', [
        'ticket_id' => $ticket->id,
        'charge_id' => $chargeId,
        'reason' => 'Fee waived',
        'items' => [['ticket_item_id' => $lineId, 'quantity' => 1, 'amount' => 50]],
    ])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/refunds/{$refundId}/approve", ['approver_id' => $manager->id, 'passcode' => '1234'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect($fee->fresh()->quantity)->toBe(0);
});

test('the back office form payload with an empty ingredients list can create a special item', function () {
    $category = specialCategory();

    // The form always sends `ingredients: []` and `modifiers: []` for a special item.
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/items', [
            'category_id' => $category->id,
            'name' => 'Delivery Fee',
            'base_price' => '0',
            'cost_price' => '0',
            'quantity' => '0',
            'inventory_type' => 'none',
            'entry_mode' => 'price',
            'status' => 'available',
            'ingredients' => [],
            'modifiers' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirectToRoute('item-management');

    expect(Item::where('name', 'Delivery Fee')->firstOrFail()->entry_mode)->toBe('price');
});
