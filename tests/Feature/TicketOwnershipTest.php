<?php

use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| Account-bound ticket access: a cashier sees and acts only on tickets they opened
| (tickets.created_by); managers and admins see and act on every ticket. KDS, receipts and
| refunds stay unscoped. terminal_id is only a label/filter, never an access rule.
*/

/** @return array{0: Ticket, 1: TicketItem, 2: Ticket} */
function ownedTicketWithLine(User $cashier): array
{
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, $item, 1);
    $other = posTicket($shift, $cashier, 'maria');

    return [$ticket, $line, $other];
}

/** Every per-ticket POS route, built against a given ticket. */
function perTicketRoutes(Ticket $ticket, TicketItem $line, Ticket $other): array
{
    return [
        'show' => ['get', "/api/v1/tickets/{$ticket->id}", []],
        'add item' => ['post', "/api/v1/tickets/{$ticket->id}/items", ['item_id' => $line->item_id, 'quantity' => 1]],
        'change quantity' => ['patch', "/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['quantity' => 2]],
        'void item' => ['delete', "/api/v1/tickets/{$ticket->id}/items/{$line->id}", ['passcode' => '1234']],
        'discount' => ['patch', "/api/v1/tickets/{$ticket->id}/discount", ['discount_amount' => 10]],
        'merge' => ['post', "/api/v1/tickets/{$ticket->id}/merge", ['merge_from_ticket_ids' => [$other->id]]],
        'cancel' => ['post', "/api/v1/tickets/{$ticket->id}/cancel", []],
        'pay' => ['post', "/api/v1/tickets/{$ticket->id}/charges", ['charges' => [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]]],
    ];
}

test('another cashier gets 404 on every per-ticket route and the ticket is left untouched', function () {
    $owner = posUser();
    posUser('manager');
    [$ticket, $line, $other] = ownedTicketWithLine($owner);

    Sanctum::actingAs(posUser(), ['*']);

    foreach (perTicketRoutes($ticket, $line, $other) as $label => [$method, $uri, $payload]) {
        $response = $this->json($method, $uri, $payload);
        expect($response->status())->toBe(404, "{$label} returned {$response->status()}")
            ->and($response->json('message'))->toBe('Resource not found.', "{$label} message");
    }

    $fresh = $ticket->fresh();
    expect($fresh->status)->toBe('open')
        ->and((float) $fresh->discount_amount)->toBe(0.0)
        ->and($fresh->items()->count())->toBe(1)
        ->and($line->fresh()->quantity)->toBe(1)
        ->and($line->fresh()->voided_at)->toBeNull()
        ->and($fresh->charges()->count())->toBe(0)
        ->and($other->fresh()->status)->toBe('open');
});

test('a manager or admin can view, add to and pay any cashier\'s ticket', function (string $role) {
    $owner = posUser();
    [$ticket, $line] = ownedTicketWithLine($owner);

    Sanctum::actingAs(posUser($role), ['*']);

    $this->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
    $this->postJson("/api/v1/tickets/{$ticket->id}/items", ['item_id' => $line->item_id, 'quantity' => 1])->assertCreated();
    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 200]],
    ])->assertOk();

    expect($ticket->fresh()->status)->toBe('paid');
})->with(['manager', 'admin']);

test('a cashier lists only the tickets they opened, in any status', function () {
    $alice = posUser();
    $bob = posUser();
    $shift = posOpenShift($alice);
    $item = posItem('Burger', 100);

    $aliceOpen = posTicket($shift, $alice, 'john');
    $alicePaid = posTicket($shift, $alice, 'jane');
    posAddItem($alicePaid, $item, 1);
    posPay($alicePaid, $alice, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);
    $bobOpen = posTicket($shift, $bob, 'maria');

    Sanctum::actingAs($alice, ['*']);
    $this->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $aliceOpen->id);
    $this->getJson('/api/v1/tickets?status=paid')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alicePaid->id);

    Sanctum::actingAs($bob, ['*']);
    $this->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bobOpen->id);
    $this->getJson('/api/v1/tickets?status=paid')->assertOk()->assertJsonCount(0, 'data');
});

test('a manager or admin lists every cashier\'s tickets, and terminal_id still narrows the list', function (string $role) {
    $alice = posUser();
    $bob = posUser();
    $shift = posOpenShift($alice);

    $one = posTicket($shift, $alice, 'john', 'POS-01');
    $two = posTicket($shift, $bob, 'maria', 'POS-02');

    Sanctum::actingAs(posUser($role), ['*']);

    $ids = collect($this->getJson('/api/v1/tickets')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    expect($ids)->toBe([$one->id, $two->id]);

    $this->getJson('/api/v1/tickets?terminal_id=POS-02')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $two->id);
})->with(['manager', 'admin']);

test('a kitchen tablet logged in as anyone still completes every cashier\'s items', function () {
    $owner = posUser();
    [$ticket, $line] = ownedTicketWithLine($owner);
    $token = posUser()->createToken('kitchen', ['kds:read', 'kds:complete'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/kds/orders')->assertOk()->assertJsonPath('data.0.ticket_id', $ticket->id);
    $this->withToken($token)->patchJson("/api/v1/kds/orders/items/{$line->id}/complete", ['completed' => true])->assertOk();
    $this->withToken($token)->patchJson("/api/v1/kds/orders/{$ticket->id}/complete")->assertOk();
});

test('receipt history still shows every cashier\'s receipts', function () {
    $owner = posUser();
    [$ticket] = ownedTicketWithLine($owner);
    posPay($ticket, $owner, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    Sanctum::actingAs(posUser(), ['*']);

    $this->getJson('/api/v1/receipts')->assertOk()->assertJsonCount(1, 'data');
});
