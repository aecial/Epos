<?php

use App\Models\Category;
use App\Models\Item;

/*
| End-to-end simulation of a cashier ringing up an order through the real HTTP API, the same
| sequence a POS terminal would make: login -> open/check shift -> browse menu -> create
| ticket -> add each line -> apply a discount -> pay in full with cash, tendering more than
| owed -> verify every total along the way (ticket, charge, receipt, shift).
|
| This complements FriedItikOrderTest (split cash/gcash) and ReceiptTest (proration) by
| covering the simpler, more common "pays in full with one cash tender" path, which neither
| of those exercises.
*/

test('a cashier can log in, order, apply a discount and pay cash, and every total is correct throughout', function () {
    // 1. Login.
    $cashier = posUser();
    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $cashier->username,
        'password' => 'password', // UserFactory's default.
        'device_name' => 'POS-01',
    ])->assertCreated();

    $token = $response->json('data.token');
    $this->withHeader('Authorization', "Bearer {$token}");

    // 2. No shift open yet -> 404 -> open one.
    $this->getJson('/api/v1/shifts/active')->assertNotFound();

    $shiftId = $this->postJson('/api/v1/shifts', ['starting_cash' => 1000])
        ->assertCreated()
        ->json('data.id');

    // 3. Browse the menu (categories + items), exactly as MenuScreen would.
    $category = Category::create(['name' => 'Mains', 'status' => 'active', 'is_visible_to_pos' => true]);
    $friedItik = Item::create([
        'category_id' => $category->id, 'name' => 'Fried Itik', 'base_price' => 150,
        'cost_price' => 60, 'quantity' => 20, 'inventory_type' => 'direct', 'status' => 'available',
    ]);
    $rice = Item::create([
        'category_id' => $category->id, 'name' => 'Rice', 'base_price' => 40,
        'cost_price' => 15, 'quantity' => 50, 'inventory_type' => 'direct', 'status' => 'available',
    ]);

    $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/items')->assertOk()->assertJsonCount(2, 'data');

    // 4. Create the ticket, then add each line one call at a time (no batch endpoint).
    $ticketId = $this->postJson('/api/v1/tickets', [
        'terminal_id' => 'POS-01', 'customer_name' => 'john', 'order_type' => 'dine_in',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/tickets/{$ticketId}/items", ['item_id' => $friedItik->id, 'quantity' => 1])
        ->assertCreated()
        ->assertJsonPath('data.subtotal', 150)
        ->assertJsonPath('data.total', 150);

    $this->postJson("/api/v1/tickets/{$ticketId}/items", ['item_id' => $rice->id, 'quantity' => 2])
        ->assertCreated()
        ->assertJsonPath('data.subtotal', 230) // 150 + 2*40
        ->assertJsonPath('data.total', 230);

    // Stock was reserved, not yet deducted.
    expect((int) $friedItik->fresh()->reserved_quantity)->toBe(1)
        ->and((int) $rice->fresh()->reserved_quantity)->toBe(2)
        ->and((int) $friedItik->fresh()->quantity)->toBe(20) // unchanged until payment
        ->and((int) $rice->fresh()->quantity)->toBe(50);

    // 5. Apply a fixed ₱30 discount: 230 - 30 = 200.
    $this->patchJson("/api/v1/tickets/{$ticketId}/discount", ['discount_amount' => 30])
        ->assertOk()
        ->assertJsonPath('data.subtotal', 230)
        ->assertJsonPath('data.total', 200);

    // 6. Pay in full with cash, tendering more than owed.
    $paid = $this->postJson("/api/v1/tickets/{$ticketId}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 200, 'tendered_amount' => 250],
        ],
    ])->assertOk();

    $paid->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.subtotal', 230)
        ->assertJsonPath('data.total', 200)
        ->assertJsonCount(1, 'data.charges');

    $charge = $paid->json('data.charges.0');
    expect((float) $charge['amount'])->toBe(200.0)
        ->and((float) $charge['tendered_amount'])->toBe(250.0)
        ->and((float) $charge['change_due'])->toBe(50.0)
        ->and($charge['payment_method'])->toBe('cash');

    // The receipt's own numbers must foot exactly to the charge: subtotal - discount = total.
    $payload = $charge['receipt']['payload'];
    expect($payload['subtotal'])->toEqual(230)
        ->and($payload['discount'])->toEqual(30)
        ->and($payload['total'])->toEqual(200)
        ->and(round($payload['subtotal'] - $payload['discount'], 2))->toEqual($payload['total'])
        ->and($payload['payment']['tendered_amount'])->toEqual(250)
        ->and($payload['payment']['change_due'])->toEqual(50)
        ->and(collect($payload['items'])->sum('line_total'))->toEqual(230);

    // 7. Inventory is deducted now (not before), reservations cleared.
    expect((int) $friedItik->fresh()->quantity)->toBe(19)
        ->and((int) $friedItik->fresh()->reserved_quantity)->toBe(0)
        ->and((int) $rice->fresh()->quantity)->toBe(48)
        ->and((int) $rice->fresh()->reserved_quantity)->toBe(0);

    // 8. Shift running totals reflect the discounted total, not the subtotal.
    $this->getJson('/api/v1/shifts/active')
        ->assertOk()
        ->assertJsonPath('data.total_revenue', 200)
        ->assertJsonPath('data.total_cash', 200)
        ->assertJsonPath('data.total_gcash', 0)
        ->assertJsonPath('data.expected_cash', 1200); // 1000 starting + 200 cash sale

    // 9. Receipt history agrees, and a paid ticket can't be charged again.
    $this->getJson('/api/v1/receipts')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/tickets/{$ticketId}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 200]],
    ])->assertStatus(409);

    // 10. Close the shift: counting exactly the expected cash leaves no discrepancy.
    $this->putJson("/api/v1/shifts/{$shiftId}/close", ['closing_cash' => 1200])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.total_revenue', 200)
        ->assertJsonPath('data.expected_cash', 1200)
        ->assertJsonPath('data.closing_cash', 1200)
        ->assertJsonPath('data.discrepancy', 0);
});
