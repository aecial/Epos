<?php

use App\Http\Requests\ModifierGroup\CreateModifierGroupRequest;
use App\Models\ModifierGroup;
use App\Models\User;

function authorizeModifierGroupRequest(string $role): bool
{
    $user = User::factory()->create(['role' => $role]);
    auth()->setUser($user);

    $request = new CreateModifierGroupRequest();
    $request->setUserResolver(fn () => auth()->user());

    return $request->authorize();
}

test('admin can read all modifier groups', function () {
    ModifierGroup::create(['name' => 'Size', 'is_required' => true]);
    ModifierGroup::create(['name' => 'Flavor', 'is_required' => false]);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/modifier-groups')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Size'])
        ->assertJsonFragment(['name' => 'Flavor']);
});

test('admin can read a single modifier group', function () {
    $group = ModifierGroup::create(['name' => 'Size', 'is_required' => true]);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/modifier-groups/' . $group->id)
        ->assertOk()
        ->assertJsonPath('name', 'Size');
});

test('admin and manager can authorize modifier group create request', function () {
    expect(authorizeModifierGroupRequest('admin'))->toBeTrue()
        ->and(authorizeModifierGroupRequest('manager'))->toBeTrue();
});

test('cashier cannot authorize modifier group create request', function () {
    expect(authorizeModifierGroupRequest('cashier'))->toBeFalse();
});
