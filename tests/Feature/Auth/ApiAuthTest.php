<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/*
| POS sign-in over /api/v1/auth: a terminal trades username + password for a Sanctum token,
| names it after the device, and keeps it until it logs out or a manager revokes it.
*/

test('a cashier logs in and gets a named token and their profile without secrets', function () {
    $cashier = User::factory()->create(['username' => 'dangbi', 'name' => 'Dang Bi']);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => 'dangbi',
        'password' => 'password',
        'device_name' => 'POS-01',
    ])->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $cashier->id)
        ->assertJsonPath('data.user.username', 'dangbi')
        ->assertJsonPath('data.user.role', 'cashier');

    expect($response->json('data.user'))->not->toHaveKeys(['password', 'passcode', 'remember_token']);

    $token = PersonalAccessToken::findToken($response->json('data.token'));
    expect($token->tokenable_id)->toBe($cashier->id)
        ->and($token->name)->toBe('POS-01');
});

test('a token without a device name is labelled pos', function () {
    User::factory()->create(['username' => 'dangbi']);

    $token = $this->postJson('/api/v1/auth/login', ['username' => 'dangbi', 'password' => 'password'])
        ->assertCreated()
        ->json('data.token');

    expect(PersonalAccessToken::findToken($token)->name)->toBe('pos');
});

test('a wrong password, an unknown username and an inactive account are refused without a token', function (string $username, string $password, string $message) {
    User::factory()->create(['username' => 'dangbi']);
    User::factory()->inactive()->create(['username' => 'gone']);

    $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.username.0', $message);

    expect(PersonalAccessToken::count())->toBe(0);
})->with([
    'wrong password' => ['dangbi', 'nope', 'These credentials do not match our records.'],
    'unknown username' => ['nobody', 'password', 'These credentials do not match our records.'],
    'inactive account' => ['gone', 'password', 'This account is inactive.'],
]);

test('managers and admins can sign in on the POS too', function (string $role) {
    User::factory()->create(['username' => 'boss', 'role' => $role]);

    $this->postJson('/api/v1/auth/login', ['username' => 'boss', 'password' => 'password'])
        ->assertCreated()
        ->assertJsonPath('data.user.role', $role);
})->with(['manager', 'admin']);

test('login needs a username and password, and only knows the kds scope', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username', 'password']);

    User::factory()->create(['username' => 'dangbi']);

    $this->postJson('/api/v1/auth/login', ['username' => 'dangbi', 'password' => 'password', 'scope' => 'admin'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scope']);
});

test('login allows six attempts a minute, then answers 429', function () {
    User::factory()->create(['username' => 'dangbi']);

    foreach (range(1, 6) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['username' => 'dangbi', 'password' => 'nope'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['username' => 'dangbi', 'password' => 'password'])
        ->assertTooManyRequests()
        ->assertJsonPath('success', false);
});

test('me returns the signed-in account', function () {
    $cashier = User::factory()->create(['username' => 'dangbi']);
    $token = $cashier->createToken('POS-01')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $cashier->id)
        ->assertJsonPath('data.username', 'dangbi')
        ->assertJsonMissingPath('data.passcode');
});

test('logout revokes only the token it was called with', function () {
    $cashier = User::factory()->create();
    $pos = $cashier->createToken('POS-01')->plainTextToken;
    $other = $cashier->createToken('POS-02')->plainTextToken;

    $this->withToken($pos)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('success', true);

    expect(PersonalAccessToken::findToken($pos))->toBeNull()
        ->and(PersonalAccessToken::findToken($other))->not->toBeNull();

    // The guard caches its user across requests in one test; forget it so the token is re-read.
    $this->app['auth']->forgetGuards();

    $this->withToken($pos)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
});

test('every POS route answers 401 in the API envelope without a token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
})->with([
    ['GET', '/api/v1/auth/me'],
    ['GET', '/api/v1/items'],
    ['POST', '/api/v1/shifts'],
    ['GET', '/api/v1/tickets'],
    ['GET', '/api/v1/refunds'],
    ['GET', '/api/v1/kds/orders'],
]);
