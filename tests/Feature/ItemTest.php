<?php

use App\Http\Requests\Item\CreateItemRequest;
use App\Models\Category;
use App\Models\Item;
use App\Models\User;

function authorizeItemRequest(string $role): bool
{
    $user = User::factory()->create(['role' => $role]);
    auth()->setUser($user);

    $request = new CreateItemRequest();
    $request->setUserResolver(fn () => auth()->user());

    return $request->authorize();
}

test('admin can read all items', function () {
    $category1 = Category::create(['name' => 'Itik', 'status' => 'active', 'is_visible_to_pos' => true]);
    $category2 = Category::create(['name' => 'Pork', 'status' => 'active', 'is_visible_to_pos' => true]);
    
    Item::create(['category_id' => $category1->id, 'name' => 'Estofadong Itik', 'base_price' => 380, 'cost_price' => 150, 'quantity' => 5, 'status' => 'available']);

    Item::create(['category_id' => $category2->id, 'name' => 'Pork Sisig', 'base_price' => 209, 'cost_price' => 120, 'quantity' => 5, 'status' => 'available']);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/items')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Estofadong Itik'])
        ->assertJsonFragment(['name' => 'Pork Sisig']);
});

test('admin can read a single item', function () {
    $category  = Category::create(['name' => 'Itik', 'status' => 'active', 'is_visible_to_pos' => true]);
    $item = Item::create(['category_id' => $category->id, 'name' => 'Fried Itik', 'base_price' => 295, 'cost_price' => 150, 'quantity' => 10, 'status' => 'available']);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/items/' . $item->id)
        ->assertOk()
        ->assertJsonPath('name', 'Fried Itik');
});
test('admin and manager can authorize item route request', function() {
    expect(authorizeItemRequest('admin'))->toBeTrue()->and(authorizeItemRequest('manager'))->toBeTrue();
});
test('cashier cant authorize an item route request', function() {
    expect(authorizeItemRequest('cashier'))->toBeFalse();
});