<?php

use App\Models\User;

/*
| The back office is for active managers/admins. Cashiers sign in on the POS (Sanctum, /api/v1),
| which this doesn't touch. LoginRequest refuses everyone else at the login screen; the
| EnsureBackOfficeUser web middleware signs out any session that still belongs to one.
*/

test('a cashier is refused at the back-office login and told to use the POS', function () {
    $cashier = User::factory()->create();

    $this->post('/login', ['username' => $cashier->username, 'password' => 'password'])
        ->assertSessionHasErrors(['username' => 'Cashier accounts sign in on the POS only.']);

    $this->assertGuest();
});

test('an inactive manager is refused at the back-office login', function () {
    $manager = User::factory()->manager()->inactive()->create();

    $this->post('/login', ['username' => $manager->username, 'password' => 'password'])
        ->assertSessionHasErrors(['username' => 'This account is inactive.']);

    $this->assertGuest();
});

test('active managers and admins get in', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->post('/login', ['username' => $user->username, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
})->with(['manager', 'admin']);

test('a wrong password still reads as a failed login, not as a role refusal', function () {
    $cashier = User::factory()->create();

    $this->post('/login', ['username' => $cashier->username, 'password' => 'wrong'])
        ->assertSessionHasErrors(['username' => __('auth.failed')]);
});

test('a cashier session is signed out on its next back-office request', function () {
    $cashier = User::factory()->create();

    $this->actingAs($cashier)
        ->get('/settings/profile')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['username' => 'Cashier accounts sign in on the POS only.']);

    $this->assertGuest();
});

test('a manager deactivated or demoted mid-session is signed out on the next request', function () {
    $deactivated = User::factory()->manager()->create();
    $demoted = User::factory()->manager()->create();

    $this->actingAs($deactivated)->get('/dashboard')->assertOk();
    $deactivated->update(['status' => 'inactive']);
    $this->actingAs($deactivated)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();

    $this->actingAs($demoted)->get('/dashboard')->assertOk();
    $demoted->update(['role' => 'cashier']);
    $this->actingAs($demoted)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

test('a cashier can still sign in on the POS', function () {
    $cashier = User::factory()->create();

    $this->postJson('/api/v1/auth/login', ['username' => $cashier->username, 'password' => 'password', 'device_name' => 'POS-01'])
        ->assertCreated()
        ->assertJsonPath('success', true);
});
