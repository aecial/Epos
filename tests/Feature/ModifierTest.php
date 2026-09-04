<?php

use App\Http\Requests\Modifier\CreateModifierRequest;
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

test('admin and manager can authorize modifier create request', function () {
    expect(authorizeModifierRequest('admin'))->toBeTrue()
        ->and(authorizeModifierRequest('manager'))->toBeTrue();
});

test('cashier cannot authorize modifier create request', function () {
    expect(authorizeModifierRequest('cashier'))->toBeFalse();
});
