<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('admin can create a user', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/users', [
            'name' => 'Jane Manager',
            'username' => 'jane.manager',
            'password' => 'password123',
            'passcode' => '2468',
            'role' => 'manager',
        ])
        ->assertRedirect(route('employee-management', absolute: false));

    $user = User::where('username', 'jane.manager')->firstOrFail();

    expect($user->name)->toBe('Jane Manager')
        ->and($user->role)->toBe('manager')
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and(Hash::check('2468', $user->passcode))->toBeTrue();
});

test('admin can update a user', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'cashier']);

    $this->actingAs($admin)
        ->patch('/users/' . $user->id, [
            'name' => 'Updated Cashier',
            'username' => 'updated.cashier',
            'password' => 'newpassword123',
            'role' => 'cashier',
            'status' => 'inactive',
        ])
        ->assertRedirect(route('employee-management', absolute: false));

    $user->refresh();

    expect($user->name)->toBe('Updated Cashier')
        ->and($user->username)->toBe('updated.cashier')
        ->and($user->status)->toBe('inactive')
        ->and(Hash::check('newpassword123', $user->password))->toBeTrue();
});

test('admin can delete a user', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'cashier']);

    $this->actingAs($admin)
        ->delete('/users/' . $user->id)
        ->assertRedirect(route('employee-management', absolute: false));

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('only managers can have a passcode when creating users', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/users', [
        'name' => 'Cashier User',
        'username' => 'cashier.user',
        'password' => 'password123',
        'passcode' => '2468',
        'role' => 'cashier',
    ])->assertRedirect(route('employee-management', absolute: false));

    $cashier = User::where('username', 'cashier.user')->firstOrFail();

    expect($cashier->passcode)->toBeNull();
});

test('only managers can have a passcode when updating users', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager', 'passcode' => '1234']);

    $this->actingAs($admin)
        ->patch('/users/' . $manager->id, [
            'role' => 'manager',
            'passcode' => '2468',
        ])
        ->assertRedirect(route('employee-management', absolute: false));

    expect(Hash::check('2468', $manager->refresh()->passcode))->toBeTrue();

    $this->actingAs($admin)
        ->patch('/users/' . $manager->id, [
            'role' => 'cashier',
            'passcode' => '1357',
        ])
        ->assertRedirect(route('employee-management', absolute: false));

    expect($manager->refresh()->role)->toBe('cashier')
        ->and($manager->passcode)->toBeNull();
});