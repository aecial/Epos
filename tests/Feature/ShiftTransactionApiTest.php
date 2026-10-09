<?php

use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Services\ShiftService;
use App\Services\ShiftTransactionService;
use Laravel\Sanctum\Sanctum;

/*
| Cash additions and expenses on the open shift. A manager/admin records and edits them; any
| signed-in staff may remove a mistaken one (deliberately ungated). Removing is a soft delete,
| and a closed shift's entries are frozen.
*/

function shiftTransactionApiOpenShift(): Shift
{
    return posOpenShift(posUser());
}

test('a manager or admin records an addition and an expense, and they move the expected cash', function (string $role) {
    $shift = shiftTransactionApiOpenShift();
    $manager = posUser($role);
    Sanctum::actingAs($manager, ['*']);

    $this->postJson("/api/v1/shifts/{$shift->id}/transactions", ['type' => 'addition', 'amount' => 500, 'reason' => 'Change fund'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'addition')
        ->assertJsonPath('data.reason', 'Change fund')
        ->assertJsonPath('data.created_by', $manager->id);

    $this->postJson("/api/v1/shifts/{$shift->id}/transactions", ['type' => 'expense', 'amount' => 120.50, 'reason' => 'Ice'])
        ->assertCreated();

    $totals = app(ShiftService::class)->ComputeTotals($shift);
    expect($totals['total_additions'])->toBe(500.0)
        ->and($totals['total_expenses'])->toBe(120.5)
        ->and($totals['expected_cash'])->toBe(1379.5);
})->with(['manager', 'admin']);

test('a cashier cannot record or edit an entry', function () {
    $shift = shiftTransactionApiOpenShift();
    $entry = ShiftTransaction::create(['shift_id' => $shift->id, 'type' => 'expense', 'amount' => 50, 'reason' => 'Ice', 'created_by' => posUser('manager')->id]);
    Sanctum::actingAs(posUser(), ['*']);

    $this->postJson("/api/v1/shifts/{$shift->id}/transactions", ['type' => 'expense', 'amount' => 50, 'reason' => 'Ice'])
        ->assertForbidden();
    $this->putJson("/api/v1/shifts/{$shift->id}/transactions/{$entry->id}", ['amount' => 5, 'reason' => 'Ice'])
        ->assertForbidden();

    expect(ShiftTransaction::count())->toBe(1)
        ->and((float) $entry->fresh()->amount)->toBe(50.0);
});

test('an entry needs a known type, a positive amount and a reason', function (array $body, array $errors) {
    $shift = shiftTransactionApiOpenShift();
    Sanctum::actingAs(posUser('manager'), ['*']);

    $this->postJson("/api/v1/shifts/{$shift->id}/transactions", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'nothing' => [[], ['type', 'amount', 'reason']],
    'unknown type' => [['type' => 'refund', 'amount' => 10, 'reason' => 'x'], ['type']],
    'zero amount' => [['type' => 'expense', 'amount' => 0, 'reason' => 'x'], ['amount']],
    'negative amount' => [['type' => 'addition', 'amount' => -10, 'reason' => 'x'], ['amount']],
    'reason too long' => [['type' => 'expense', 'amount' => 10, 'reason' => str_repeat('a', 256)], ['reason']],
]);

test('a manager edits the amount and reason; the type stays', function () {
    $shift = shiftTransactionApiOpenShift();
    $manager = posUser('manager');
    $entry = app(ShiftTransactionService::class)->AddTransaction($shift, $manager, 'expense', 50, 'Ice');
    Sanctum::actingAs($manager, ['*']);

    $this->putJson("/api/v1/shifts/{$shift->id}/transactions/{$entry->id}", ['amount' => 75, 'reason' => 'Ice x2', 'type' => 'addition'])
        ->assertOk()
        ->assertJsonPath('data.reason', 'Ice x2')
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.updated_by', $manager->id);

    expect((float) $entry->fresh()->amount)->toBe(75.0);
});

test('any staff member can remove a mistaken entry, which drops out of the list and the totals', function () {
    $shift = shiftTransactionApiOpenShift();
    $manager = posUser('manager');
    $service = app(ShiftTransactionService::class);
    $kept = $service->AddTransaction($shift, $manager, 'expense', 50, 'Ice');
    $mistake = $service->AddTransaction($shift, $manager, 'expense', 999, 'Typo');
    $cashier = posUser();
    Sanctum::actingAs($cashier, ['*']);

    $this->deleteJson("/api/v1/shifts/{$shift->id}/transactions/{$mistake->id}")
        ->assertOk()
        ->assertJsonPath('data.message', 'Transaction deleted.');

    // Soft-deleted: the row stays for the audit trail, with who removed it.
    expect($mistake->fresh()->deleted_at)->not->toBeNull()
        ->and($mistake->fresh()->deleted_by)->toBe($cashier->id);

    $listed = $this->getJson("/api/v1/shifts/{$shift->id}/transactions")->assertOk()->json('data');
    expect(collect($listed)->pluck('id')->all())->toBe([$kept->id]);

    expect(app(ShiftService::class)->ComputeTotals($shift)['total_expenses'])->toBe(50.0);
});

test('the list shows only that shift\'s entries, newest first', function () {
    $manager = posUser('manager');
    $old = posOpenShift($manager);
    $service = app(ShiftTransactionService::class);
    $service->AddTransaction($old, $manager, 'expense', 10, 'Old shift');
    app(ShiftService::class)->CloseShift($old, $manager, 990);

    $shift = posOpenShift($manager);
    $first = $service->AddTransaction($shift, $manager, 'addition', 100, 'First');
    $this->travel(1)->minutes();
    $second = $service->AddTransaction($shift, $manager, 'expense', 20, 'Second');
    Sanctum::actingAs(posUser(), ['*']);

    $listed = $this->getJson("/api/v1/shifts/{$shift->id}/transactions")->assertOk()->json('data');

    expect(collect($listed)->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('an entry is reached only through its own shift', function () {
    $manager = posUser('manager');
    $old = posOpenShift($manager);
    $entry = app(ShiftTransactionService::class)->AddTransaction($old, $manager, 'expense', 10, 'Ice');
    app(ShiftService::class)->CloseShift($old, $manager, 990);
    $shift = posOpenShift($manager);
    Sanctum::actingAs($manager, ['*']);

    $this->putJson("/api/v1/shifts/{$shift->id}/transactions/{$entry->id}", ['amount' => 1, 'reason' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/shifts/{$shift->id}/transactions/{$entry->id}")->assertNotFound();

    expect((float) $entry->fresh()->amount)->toBe(10.0)
        ->and($entry->fresh()->deleted_at)->toBeNull();
});

test('a closed shift\'s entries are frozen', function () {
    $manager = posUser('manager');
    $shift = posOpenShift($manager);
    $entry = app(ShiftTransactionService::class)->AddTransaction($shift, $manager, 'expense', 10, 'Ice');
    app(ShiftService::class)->CloseShift($shift, $manager, 990);
    Sanctum::actingAs($manager, ['*']);

    $this->postJson("/api/v1/shifts/{$shift->id}/transactions", ['type' => 'expense', 'amount' => 5, 'reason' => 'Late'])
        ->assertConflict()
        ->assertJsonPath('message', 'Cannot add a transaction to a closed shift.');
    $this->putJson("/api/v1/shifts/{$shift->id}/transactions/{$entry->id}", ['amount' => 5, 'reason' => 'x'])
        ->assertConflict()
        ->assertJsonPath('message', 'Cannot edit a transaction on a closed shift.');
    $this->deleteJson("/api/v1/shifts/{$shift->id}/transactions/{$entry->id}")
        ->assertConflict()
        ->assertJsonPath('message', 'Cannot delete a transaction on a closed shift.');

    expect(ShiftTransaction::count())->toBe(1)
        ->and((float) $entry->fresh()->amount)->toBe(10.0)
        ->and($entry->fresh()->deleted_at)->toBeNull();
});
