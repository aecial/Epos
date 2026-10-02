<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Sanctum abilities are opt-in: a route only enforces them if explicitly gated. A kds-scoped
| token is a real restriction only because every pre-existing route now requires
| ability:full-access, while a token minted without scope still defaults to Sanctum's ['*'],
| which satisfies any ability check - so these tests also guard against that restructuring
| silently breaking every other POS endpoint.
*/

test('logging in with scope=kds issues a token restricted to kds:read and kds:complete', function () {
    $user = User::factory()->create(['role' => 'cashier']);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => 'password',
        'scope' => 'kds',
    ])->assertStatus(201);

    $token = $response->json('data.token');
    $accessToken = PersonalAccessToken::findToken(explode('|', $token)[1]);

    expect($accessToken->abilities)->toBe(['kds:read', 'kds:complete']);
});

test('logging in without a scope issues a full-access token', function () {
    $user = User::factory()->create(['role' => 'cashier']);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => 'password',
    ])->assertStatus(201);

    $token = $response->json('data.token');
    $accessToken = PersonalAccessToken::findToken(explode('|', $token)[1]);

    expect($accessToken->abilities)->toBe(['*']);
});

test('a kds-scoped token can reach both kds routes', function () {
    $user = User::factory()->create(['role' => 'cashier']);
    $token = $user->createToken('kiosk', ['kds:read', 'kds:complete'])->plainTextToken;

    $shift = posOpenShift($user);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $user, 'john');
    $line = posAddItem($ticket, $item, 1);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/kds/orders')
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => true])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/kds/orders/{$ticket->id}/complete")
        ->assertOk();
});

test('a kds-scoped token is forbidden from every full-access route', function () {
    $user = User::factory()->create(['role' => 'cashier']);
    $token = $user->createToken('kiosk', ['kds:read', 'kds:complete'])->plainTextToken;

    $shift = posOpenShift($user);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $user, 'john');

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/items')->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/tickets', [
        'terminal_id' => 'POS-01', 'customer_name' => 'jane', 'order_type' => 'dine_in',
    ])->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/v1/tickets/{$ticket->id}/items", [
        'item_id' => $item->id, 'quantity' => 1,
    ])->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 0]],
    ])->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/refunds')->assertForbidden();
});

test('an unscoped (full-access) token still works everywhere, including kds routes', function () {
    $user = User::factory()->create(['role' => 'cashier']);
    $token = $user->createToken('pos')->plainTextToken;

    posOpenShift($user);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/items')->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/kds/orders')->assertOk();
});

test('auth/me and auth/logout are reachable regardless of token scope', function () {
    $user = User::factory()->create(['role' => 'cashier']);
    $token = $user->createToken('kiosk', ['kds:read', 'kds:complete'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me')->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout')->assertOk();
});
