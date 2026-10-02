<?php

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

/*
| POST /api/v1/tickets/{ticket}/items with modifier_ids. A modifier is only sellable on an
| item when it is attached to that item, active on the item_modifier pivot, and active
| globally - the same rule the POS menu (ItemService::menuQuery) uses to decide what to show.
*/

function burgerWithModifiers(): array
{
    $burger = posItem('Burger', 100);
    $group = ModifierGroup::create(['name' => 'Add-ons']);

    $cheese = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Cheese']);
    $bacon = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Bacon']);
    $retired = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Truffle', 'status' => 'inactive']);
    $unattached = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Egg']);

    $burger->modifiers()->attach([
        $cheese->id => ['price_modifier' => 20, 'status' => 'active', 'display_order' => 1],
        $bacon->id => ['price_modifier' => 30, 'status' => 'inactive', 'display_order' => 2],
        $retired->id => ['price_modifier' => 90, 'status' => 'active', 'display_order' => 3],
    ]);

    return [$burger, $cheese, $bacon, $retired, $unattached];
}

test('an active modifier attached to the item is priced onto the line', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    [$burger, $cheese] = burgerWithModifiers();
    $ticket = posTicket($shift, $cashier, 'john');

    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", [
        'item_id' => $burger->id,
        'quantity' => 2,
        'modifier_ids' => [$cheese->id],
    ])->assertSuccessful()
        ->assertJsonPath('data.items.0.line_total', 240); // (100 + 20) * 2
});

test('a modifier that cannot be sold on this item is rejected', function (string $which) {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    [$burger, , $bacon, $retired, $unattached] = burgerWithModifiers();
    $ticket = posTicket($shift, $cashier, 'john');

    $modifier = ['inactive on this item' => $bacon, 'inactive globally' => $retired, 'not attached to this item' => $unattached][$which];

    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", [
        'item_id' => $burger->id,
        'quantity' => 1,
        'modifier_ids' => [$modifier->id],
    ])->assertStatus(422)
        ->assertJsonValidationErrors('modifier_ids');

    expect($ticket->items()->count())->toBe(0);
})->with(['inactive on this item', 'inactive globally', 'not attached to this item']);

test('the service refuses an unsellable modifier even when called directly', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    [$burger, $cheese, $bacon] = burgerWithModifiers();
    $ticket = posTicket($shift, $cashier, 'john');

    expect(fn () => app(TicketService::class)->AddItem($ticket, $burger, 1, [$cheese->id, $bacon->id]))
        ->toThrow(InvalidArgumentException::class);

    expect($ticket->items()->count())->toBe(0);
});
