<?php

use App\Http\Requests\Modifier\CreateModifierRequest;
use App\Models\Category;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\User;

function authorizeModifierRequest(string $role): bool
{
    $user = User::factory()->create(['role' => $role]);
    auth()->setUser($user);

    $request = new CreateModifierRequest();
    $request->setUserResolver(fn () => auth()->user());

    return $request->authorize();
}

test('admin can read all modifiers', function () {
    $group = ModifierGroup::create(['name' => 'Size', 'is_required' => true]);

    Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Small', 'status' => 'active']);
    Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Large', 'status' => 'active']);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/modifiers')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Small'])
        ->assertJsonFragment(['name' => 'Large']);
});

test('admin can read a single modifier', function () {
    $group = ModifierGroup::create(['name' => 'Size', 'is_required' => true]);
    $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Medium', 'status' => 'active']);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/modifiers/' . $modifier->id)
        ->assertOk()
        ->assertJsonPath('name', 'Medium');
});

test('fried itik has chop and whole modifiers in the itik category', function () {
    $category = Category::create(['name' => 'Itik', 'status' => 'active', 'is_visible_to_pos' => true]);
    $item = Item::create([
        'category_id' => $category->id,
        'name' => 'Fried Itik',
        'base_price' => 295,
        'cost_price' => 120,
        'quantity' => 10,
        'reserved_quantity' => 0,
        'status' => 'available',
    ]);

    $group = ModifierGroup::create(['name' => 'Cut', 'is_required' => true]);
    $chop = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Chop', 'status' => 'active']);
    $whole = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Whole', 'status' => 'active']);

    $item->modifiers()->attach([
        $chop->id => ['price_modifier' => 0, 'status' => 'active', 'display_order' => 1],
        $whole->id => ['price_modifier' => 25, 'status' => 'active', 'display_order' => 2],
    ]);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/items/' . $item->id)
        ->assertOk()
        ->assertJsonPath('name', 'Fried Itik');

    $this->get('/modifiers')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Chop'])
        ->assertJsonFragment(['name' => 'Whole']);
});

test('admin and manager can authorize modifier create request', function () {
    expect(authorizeModifierRequest('admin'))->toBeTrue()
        ->and(authorizeModifierRequest('manager'))->toBeTrue();
});

test('cashier cannot authorize modifier create request', function () {
    expect(authorizeModifierRequest('cashier'))->toBeFalse();
});
