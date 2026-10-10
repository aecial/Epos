<?php

use App\Models\Category;

/*
| GET /api/v1/sync/snapshot: everything a phone keeps on hand to sell with no server - the menu,
| the open shift, its own open tickets, its device code and the server's clock.
*/

test('the snapshot holds the menu, the open shift, my open tickets, my device code and the server time', function () {
    $phone = syncPhone();
    $other = posUser();
    $shift = posOpenShift($phone['user']);
    $burger = posItem('Burger', 100, 7);
    $mine = posTicket($shift, $phone['user'], 'John');
    posAddItem($mine, $burger, 2);
    posTicket($shift, $other, 'Mary');
    Category::create(['name' => 'Hidden from POS', 'status' => 'active', 'is_visible_to_pos' => false]);

    $data = $this->withToken($phone['token'])->getJson('/api/v1/sync/snapshot')->assertOk()->json('data');

    expect($data['device'])->toBe(['code' => $phone['device']->code, 'name' => 'POS-01'])
        ->and($data['server_time'])->not->toBeEmpty()
        ->and($data['menu_version'])->toBeString()
        ->and($data['shift']['id'])->toBe($shift->id)
        ->and(collect($data['categories'])->pluck('name')->all())->toBe(['Test Category'])
        ->and($data['items'][0])->toMatchArray(['name' => 'Burger', 'available_stock' => 5])
        // A cashier's phone keeps only the tickets they opened, with their lines.
        ->and(collect($data['open_tickets'])->pluck('customer_name')->all())->toBe(['John'])
        ->and($data['open_tickets'][0]['items'][0]['quantity'])->toBe(2);
});

test('the menu version changes when the menu does, and only then', function () {
    $phone = syncPhone();
    $burger = posItem('Burger', 100);
    $version = fn (): string => $this->withToken($phone['token'])->getJson('/api/v1/sync/snapshot')->json('data.menu_version');

    $before = $version();
    expect($version())->toBe($before);

    $this->travel(1)->minutes();
    $burger->update(['base_price' => 120]);

    expect($version())->not->toBe($before);
});

test('with no shift open the snapshot says so, and the phone may open one offline', function () {
    $phone = syncPhone();

    $this->withToken($phone['token'])->getJson('/api/v1/sync/snapshot')
        ->assertOk()
        ->assertJsonPath('data.shift', null)
        ->assertJsonPath('data.open_tickets', []);
});
