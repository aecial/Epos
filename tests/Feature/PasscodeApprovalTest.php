<?php

use App\Exceptions\InvalidPasscodeException;
use App\Models\Refund;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\RefundService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| The POS never knows approver ids: a manager/admin types their passcode and the server works
| out whose it is. Only active admins/managers match, a passcode shared by two approvers is
| refused, and failed attempts are throttled per logged-in user.
|
| Note: the UserFactory gives every user passcode '1234', so approvers here get distinct ones.
*/

/** An open ticket with one Burger line, operated by $cashier. */
function passcodeLine(User $cashier): TicketItem
{
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'john');

    return posAddItem($ticket, posItem('Burger', 100));
}

function voidLine(TicketItem $line, string $passcode): TestResponse
{
    return test()->deleteJson("/api/v1/tickets/{$line->ticket_id}/items/{$line->id}", ['passcode' => $passcode]);
}

/** A paid ticket with a pending refund for its only line. */
function pendingRefund(User $cashier): Refund
{
    $line = passcodeLine($cashier);
    $paid = posPay($line->ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 100]]);

    return app(RefundService::class)->RequestRefund(
        $paid,
        $paid->charges->first(),
        $cashier,
        [['ticket_item_id' => $line->id, 'quantity' => 1, 'amount' => 100]],
        'Cold food',
    );
}

// ---------------------------------------------------------------- void

test('voiding with a manager passcode records that manager as the approver', function () {
    $cashier = posUser();
    $manager = User::factory()->create(['role' => 'manager', 'passcode' => '2468', 'name' => 'Kring']);
    $line = passcodeLine($cashier);
    Sanctum::actingAs($cashier, ['*']);

    voidLine($line, '2468')
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('meta.approver', ['id' => $manager->id, 'name' => 'Kring']);

    $line->refresh();

    expect($line->isVoided())->toBeTrue()
        ->and($line->voided_by)->toBe($manager->id)
        ->and($line->voided_requested_by)->toBe($cashier->id);
});

test('an admin passcode also approves a void', function () {
    $cashier = posUser();
    $admin = User::factory()->create(['role' => 'admin', 'passcode' => '5046']);
    $line = passcodeLine($cashier);
    Sanctum::actingAs($cashier, ['*']);

    voidLine($line, '5046')->assertOk()->assertJsonPath('meta.approver.id', $admin->id);

    expect($line->fresh()->voided_by)->toBe($admin->id);
});

test('a passcode that does not belong to an active manager or admin is refused', function (array $holder) {
    $cashier = posUser(); // has the factory passcode '1234'
    User::factory()->create($holder);
    $line = passcodeLine($cashier);
    Sanctum::actingAs($cashier, ['*']);

    voidLine($line, '1234')
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Passcode is invalid or the approver lacks permission.');

    expect($line->fresh()->isVoided())->toBeFalse();
})->with([
    'wrong passcode' => [['role' => 'manager', 'passcode' => '2468']],
    'cashier passcode' => [['role' => 'cashier', 'passcode' => '1234']],
    'inactive manager passcode' => [['role' => 'manager', 'status' => 'inactive', 'passcode' => '1234']],
]);

test('a passcode shared by two approvers is refused rather than guessed', function () {
    $cashier = posUser();
    User::factory()->create(['role' => 'manager', 'passcode' => '2468']);
    User::factory()->create(['role' => 'admin', 'passcode' => '2468']);
    $line = passcodeLine($cashier);
    Sanctum::actingAs($cashier, ['*']);

    voidLine($line, '2468')
        ->assertForbidden()
        ->assertJsonPath('message', 'This passcode is shared by more than one manager. It must be changed in the back office before it can be used.');

    expect($line->fresh()->isVoided())->toBeFalse();
});

test('the void request only needs a four digit passcode', function () {
    $cashier = posUser();
    $line = passcodeLine($cashier);
    Sanctum::actingAs($cashier, ['*']);

    voidLine($line, '12')->assertUnprocessable()->assertJsonValidationErrors('passcode');

    $this->deleteJson("/api/v1/tickets/{$line->ticket_id}/items/{$line->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('passcode');
});

test('the service enforces the passcode even when called directly', function () {
    $cashier = posUser();
    User::factory()->create(['role' => 'manager', 'passcode' => '2468']);
    $line = passcodeLine($cashier);

    expect(fn () => app(TicketService::class)->VoidItem($line, $cashier, '9999'))
        ->toThrow(InvalidPasscodeException::class);

    expect($line->fresh()->isVoided())->toBeFalse();
});

// ---------------------------------------------------------------- refunds

test('approving a refund records the manager identified by the passcode', function () {
    $cashier = posUser();
    $manager = User::factory()->create(['role' => 'manager', 'passcode' => '2468', 'name' => 'Kring']);
    $refund = pendingRefund($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->putJson("/api/v1/refunds/{$refund->id}/approve", ['passcode' => '2468'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by', $manager->id)
        ->assertJsonPath('meta.approver', ['id' => $manager->id, 'name' => 'Kring']);

    expect($refund->fresh()->approved_by)->toBe($manager->id);
});

test('rejecting a refund records the admin identified by the passcode', function () {
    $cashier = posUser();
    $admin = User::factory()->create(['role' => 'admin', 'passcode' => '5046']);
    $refund = pendingRefund($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->putJson("/api/v1/refunds/{$refund->id}/reject", ['passcode' => '5046'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('meta.approver.id', $admin->id);

    expect($refund->fresh()->approved_by)->toBe($admin->id);
});

test('a refund cannot be decided with a wrong passcode', function () {
    $cashier = posUser();
    User::factory()->create(['role' => 'manager', 'passcode' => '2468']);
    $refund = pendingRefund($cashier);
    Sanctum::actingAs($cashier, ['*']);

    $this->putJson("/api/v1/refunds/{$refund->id}/approve", ['passcode' => '1111'])->assertForbidden();
    $this->putJson("/api/v1/refunds/{$refund->id}/reject", ['passcode' => '1111'])->assertForbidden();

    expect($refund->fresh()->status)->toBe('pending');
});

// ---------------------------------------------------------------- throttling

test('five failed passcode attempts lock the user out for a minute', function () {
    $cashier = posUser();
    User::factory()->create(['role' => 'manager', 'passcode' => '2468']);
    $line = passcodeLine($cashier);
    $second = posAddItem($line->ticket, posItem('Fries', 50));
    Sanctum::actingAs($cashier, ['*']);

    foreach (range(1, 5) as $attempt) {
        voidLine($line, '9999')->assertForbidden();
    }

    // Even the right passcode is refused while locked out.
    voidLine($line, '2468')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Too many incorrect passcode attempts.'));

    expect($line->fresh()->isVoided())->toBeFalse();

    // The lock is per logged-in user: another cashier is unaffected (on their own ticket -
    // a cashier can't reach someone else's).
    $other = posUser();
    $otherLine = posAddItem(posTicket($line->ticket->shift, $other, 'maria'), posItem('Soup', 80));
    Sanctum::actingAs($other, ['*']);
    voidLine($otherLine, '2468')->assertOk();

    // ...and it expires.
    $this->travel(61)->seconds();
    Sanctum::actingAs($cashier, ['*']);
    voidLine($second, '2468')->assertOk();
});

test('a successful passcode clears the failed attempt count', function () {
    $cashier = posUser();
    User::factory()->create(['role' => 'manager', 'passcode' => '2468']);
    $first = passcodeLine($cashier);
    $second = posAddItem($first->ticket, posItem('Fries', 50));
    Sanctum::actingAs($cashier, ['*']);

    foreach (range(1, 4) as $attempt) {
        voidLine($first, '9999')->assertForbidden();
    }

    voidLine($first, '2468')->assertOk();

    // Without the reset this would be failures 5-8 and the next call a 429.
    foreach (range(1, 4) as $attempt) {
        voidLine($second, '9999')->assertForbidden();
    }

    voidLine($second, '2468')->assertOk();
});

// ---------------------------------------------------------------- back office uniqueness

test('a new manager cannot reuse the passcode of another active manager or admin', function (string $passcode) {
    $admin = User::factory()->create(['role' => 'admin', 'passcode' => '5046']);
    User::factory()->create(['role' => 'manager', 'passcode' => '0428']);

    $this->actingAs($admin)
        ->post('/users', [
            'name' => 'New Manager',
            'username' => 'new.manager',
            'password' => 'password123',
            'passcode' => $passcode,
            'role' => 'manager',
        ])
        ->assertSessionHasErrors(['passcode' => 'This passcode is already in use. Choose a different one.']);

    expect(User::where('username', 'new.manager')->exists())->toBeFalse();
})->with([
    'another manager' => '0428',
    'the admin' => '5046',
]);

test('passcodes of cashiers and inactive managers do not block a new manager', function () {
    $admin = User::factory()->create(['role' => 'admin', 'passcode' => '5046']);
    User::factory()->create(['role' => 'cashier', 'passcode' => '1357']);
    User::factory()->create(['role' => 'manager', 'status' => 'inactive', 'passcode' => '1357']);

    $this->actingAs($admin)
        ->post('/users', [
            'name' => 'New Manager',
            'username' => 'new.manager',
            'password' => 'password123',
            'passcode' => '1357',
            'role' => 'manager',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('employee-management', absolute: false));
});

test('updating a manager rejects another approver\'s passcode but accepts their own', function () {
    $admin = User::factory()->create(['role' => 'admin', 'passcode' => '5046']);
    $manager = User::factory()->create(['role' => 'manager', 'passcode' => '2468']);
    User::factory()->create(['role' => 'manager', 'passcode' => '0428']);

    $this->actingAs($admin)
        ->patch("/users/{$manager->id}", ['role' => 'manager', 'passcode' => '0428'])
        ->assertSessionHasErrors(['passcode' => 'This passcode is already in use. Choose a different one.']);

    expect(Hash::check('2468', $manager->fresh()->passcode))->toBeTrue();

    $this->actingAs($admin)
        ->patch("/users/{$manager->id}", ['name' => 'Renamed', 'role' => 'manager', 'passcode' => '2468'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('employee-management', absolute: false));

    expect($manager->fresh()->name)->toBe('Renamed')
        ->and(Hash::check('2468', $manager->fresh()->passcode))->toBeTrue();
});

test('demoting a manager to cashier ignores the passcode and clears it', function () {
    $admin = User::factory()->create(['role' => 'admin', 'passcode' => '5046']);
    $manager = User::factory()->create(['role' => 'manager', 'passcode' => '2468']);

    // '5046' is the admin's, but a cashier cannot hold a passcode so it is never checked.
    $this->actingAs($admin)
        ->patch("/users/{$manager->id}", ['role' => 'cashier', 'passcode' => '5046'])
        ->assertSessionHasNoErrors();

    expect($manager->fresh()->role)->toBe('cashier')
        ->and($manager->fresh()->passcode)->toBeNull();
});
