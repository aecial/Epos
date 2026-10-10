<?php

use App\Models\Category;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\SyncIssue;
use App\Models\TicketItem;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| The POS may only sell what its menu offers: an available item in an active, POS-visible
| category, with a pick from every required modifier group. Online the server refuses anything
| else; offline the sale already happened, so it is kept and flagged for a manager.
*/

/** Fried Itik with a required Size group (Regular +₱0, Large +₱50) and an optional Extra rice. */
function menuRulesItik(): Item
{
    $itik = posItem('Fried Itik', 450);
    $size = ModifierGroup::create(['name' => 'Size', 'is_required' => true]);
    $extras = ModifierGroup::create(['name' => 'Extras', 'is_required' => false]);

    foreach ([['Regular', 0, $size], ['Large', 50, $size], ['Extra rice', 25, $extras]] as $order => [$name, $price, $group]) {
        $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => $name, 'status' => 'active']);
        $itik->modifiers()->attach($modifier->id, ['price_modifier' => $price, 'status' => 'active', 'display_order' => $order]);
    }

    return $itik;
}

function menuRulesModifier(string $name): int
{
    return Modifier::query()->where('name', $name)->value('id');
}

test('an item can be sold once every required group has a pick', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'John');
    $itik = menuRulesItik();
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $itik->id, 'quantity' => 1, 'modifier_ids' => [menuRulesModifier('Extra rice')]])
        ->assertConflict()
        ->assertJsonPath('message', 'Fried Itik needs a choice of Size.');

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $itik->id, 'quantity' => 1, 'modifier_ids' => [menuRulesModifier('Large'), menuRulesModifier('Extra rice')]])
        ->assertCreated();

    expect((float) TicketItem::sole()->line_total)->toBe(525.0);
});

test('a required group with no active modifiers on the item asks for nothing', function () {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'John');
    $itik = menuRulesItik();
    foreach (['Regular', 'Large'] as $size) {
        $itik->modifiers()->updateExistingPivot(menuRulesModifier($size), ['status' => 'inactive']);
    }
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $itik->id, 'quantity' => 1])->assertCreated();
});

test('an item off the menu is refused online', function (Closure $takeOff, string $message) {
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'John');
    $burger = posItem('Burger', 100);
    $takeOff($burger);
    Sanctum::actingAs($cashier, ['*']);

    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $burger->id, 'quantity' => 1])
        ->assertConflict()
        ->assertJsonPath('message', $message);

    expect(TicketItem::count())->toBe(0)
        ->and($burger->fresh()->reserved_quantity)->toBe(0);
})->with([
    'unavailable' => [fn (Item $item) => $item->update(['status' => 'unavailable']), 'Burger is unavailable right now.'],
    'hidden' => [fn (Item $item) => $item->update(['status' => 'hidden']), 'Burger is not on the POS menu.'],
    'category hidden from the POS' => [fn (Item $item) => $item->category->update(['is_visible_to_pos' => false]), 'Burger is not on the POS menu.'],
    'category inactive' => [fn (Item $item) => $item->category->update(['status' => 'inactive']), 'Burger is not on the POS menu.'],
]);

test('an online sync add keeps the same rules', function () {
    $phone = syncPhone();
    posOpenShift($phone['user']);
    $itik = menuRulesItik();
    $ticket = syncUuid();

    $results = syncSend($phone['token'], [
        syncAction('ticket.create', ['ticket_uuid' => $ticket, 'terminal_id' => 'POS', 'customer_name' => 'John', 'order_type' => 'dine_in']),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => syncUuid(), 'item_id' => $itik->id, 'quantity' => 1]),
    ])->assertOk()->json('data.results');

    expect($results[1]['status'])->toBe('rejected')
        ->and($results[1]['message'])->toBe('Fried Itik needs a choice of Size.')
        ->and(SyncIssue::count())->toBe(0);
});

test('an offline sale that broke the menu rules is kept and flagged', function () {
    $this->travelTo(Carbon::parse('2026-10-10 18:00', 'Asia/Manila'));
    $phone = syncPhone();
    $shift = posOpenShift($phone['user']);
    $shift->update(['opened_at' => Carbon::parse('2026-10-10 09:00', 'Asia/Manila')]);
    $itik = menuRulesItik();
    $itik->update(['status' => 'unavailable']);
    $ticket = syncUuid();

    $result = syncSend($phone['token'], [
        syncAction('ticket.create', ['ticket_uuid' => $ticket, 'terminal_id' => 'POS', 'customer_name' => 'John', 'order_type' => 'dine_in'], '2026-10-10 12:00'),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => syncUuid(), 'item_id' => $itik->id, 'quantity' => 1, 'unit_price' => 450], '2026-10-10 12:01'),
    ])->assertOk()->json('data.results.1');

    expect($result['status'])->toBe('applied_with_issue')
        ->and(TicketItem::sole()->item_name)->toBe('Fried Itik')
        ->and(collect($result['issues'])->pluck('type')->sort()->values()->all())->toBe(['item_unavailable', 'required_choice_missing'])
        ->and(SyncIssue::firstWhere('type', 'required_choice_missing')->message)
        ->toBe("Sold offline on {$phone['device']->code}: Fried Itik needs a choice of Size.");
});

test('a payment through sync returns each printable receipt', function () {
    $phone = syncPhone();
    posOpenShift($phone['user']);
    $burger = posItem('Burger', 100);
    $ticket = syncUuid();

    $receipts = syncSend($phone['token'], [
        syncAction('ticket.create', ['ticket_uuid' => $ticket, 'terminal_id' => 'POS-01', 'customer_name' => 'John', 'order_type' => 'dine_in']),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => syncUuid(), 'item_id' => $burger->id, 'quantity' => 2]),
        syncAction('ticket.charge', ['ticket_uuid' => $ticket, 'charges' => [
            ['charge_uuid' => syncUuid(), 'payment_method' => 'cash', 'amount' => 150, 'tendered_amount' => 200],
            ['charge_uuid' => syncUuid(), 'payment_method' => 'gcash', 'amount' => 50, 'payment_reference' => 'GC-9'],
        ]]),
    ])->assertOk()->json('data.results.2.result.receipts');

    expect($receipts)->toHaveCount(2)
        ->and($receipts[0]['payload']['receipt_number'])->toBe($receipts[0]['receipt_number'])
        ->and($receipts[0]['payload']['items'][0])->toMatchArray(['name' => 'Burger', 'quantity' => 2, 'line_total' => 200])
        ->and($receipts[0]['payload']['payment'])->toMatchArray(['method' => 'cash', 'amount' => 150, 'change_due' => 50])
        ->and($receipts[1]['payload']['payment'])->toMatchArray(['method' => 'gcash', 'reference' => 'GC-9']);
});

test('the POS menu leaves out items whose category is inactive, like the categories list does', function () {
    $burger = posItem('Burger', 100);
    $closed = Category::create(['name' => 'Breakfast', 'status' => 'inactive', 'is_visible_to_pos' => true]);
    $silog = posItem('Tapsilog', 120);
    $silog->update(['category_id' => $closed->id]);
    Sanctum::actingAs(posUser(), ['*']);

    expect(collect($this->getJson('/api/v1/items')->assertOk()->json('data'))->pluck('name')->all())->toBe(['Burger']);
    $this->getJson("/api/v1/items/{$silog->id}")->assertNotFound();
    $this->getJson("/api/v1/items/{$burger->id}")->assertOk();
});
