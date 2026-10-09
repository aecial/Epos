<?php

use App\Models\User;

test('a manager can view a cashier\'s signed-in devices', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $cashier->createToken('POS-01');
    $cashier->createToken('POS-02');

    $this->actingAs($manager)
        ->get("/users/{$cashier->id}/sessions")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('UserSessionsPage')
            ->where('user.id', $cashier->id)
            ->has('sessions', 2)
        );
});

test('a cashier cannot view another user\'s devices', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $other = User::factory()->create(['role' => 'cashier']);
    $other->createToken('POS-01');

    $this->actingAs($cashier)->get("/users/{$other->id}/sessions")->assertRedirect('/login');
});

test('revoking one device deletes only that token and the token stops authenticating', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $tokenA = $cashier->createToken('POS-01');
    $tokenB = $cashier->createToken('POS-02');

    $this->actingAs($manager)
        ->delete("/users/{$cashier->id}/sessions/{$tokenA->accessToken->id}")
        ->assertRedirect(route('users.sessions', $cashier));

    expect($cashier->tokens()->count())->toBe(1)
        ->and($cashier->tokens()->first()->id)->toBe($tokenB->accessToken->id);
});

test('a revoked token can no longer authenticate against the API', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $token = $cashier->createToken('POS-01');

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    $this->actingAs($manager)->delete("/users/{$cashier->id}/sessions/{$token->accessToken->id}");

    // actingAs() leaves the manager signed in on the web guard for the rest of the test, and
    // Sanctum treats the testing domain as stateful — so without clearing that, this next
    // call would pass via the leftover session instead of the (now-invalid) bearer token. A
    // real POS client never sends that cookie, so this is purely a test-isolation step.
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

test('revoking all devices removes every token for that user but no one else\'s', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $otherCashier = User::factory()->create(['role' => 'cashier']);
    $cashier->createToken('POS-01');
    $cashier->createToken('POS-02');
    $otherCashier->createToken('POS-03');

    $this->actingAs($manager)
        ->delete("/users/{$cashier->id}/sessions")
        ->assertRedirect(route('users.sessions', $cashier));

    expect($cashier->tokens()->count())->toBe(0)
        ->and($otherCashier->tokens()->count())->toBe(1);
});

test('a cashier cannot revoke anyone\'s device', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $other = User::factory()->create(['role' => 'cashier']);
    $token = $other->createToken('POS-01');

    $this->actingAs($cashier)->delete("/users/{$other->id}/sessions/{$token->accessToken->id}")->assertRedirect('/login');
    $this->actingAs($cashier)->delete("/users/{$other->id}/sessions")->assertRedirect('/login');

    expect($other->tokens()->count())->toBe(1);
});

test('an admin\'s devices cannot be viewed or revoked, even by another admin', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $otherAdmin = User::factory()->create(['role' => 'admin']);
    $token = $admin->createToken('POS-01');

    $this->actingAs($otherAdmin)->get("/users/{$admin->id}/sessions")->assertForbidden();
    $this->actingAs($otherAdmin)->delete("/users/{$admin->id}/sessions/{$token->accessToken->id}")->assertForbidden();
    $this->actingAs($otherAdmin)->delete("/users/{$admin->id}/sessions")->assertForbidden();
});
