<?php

use App\Models\Charge;
use App\Models\Receipt;
use App\Models\ReceiptPrint;
use App\Models\TicketItem;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use App\Services\TicketService;
use Laravel\Sanctum\Sanctum;

test('paying issues one receipt per charge, numbered in sequence, with each charge\'s slice of the bill', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, posItem('Fried Itik', 100), 2, 'Extra crispy');
    app(TicketService::class)->SetDiscount($ticket, 0, 10); // 200 - 10% = 180

    Sanctum::actingAs($cashier);

    $today = now()->toDateString();

    $response = $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [
            ['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 150],
            ['payment_method' => 'gcash', 'amount' => 80, 'payment_reference' => 'GC1'],
        ],
    ])->assertOk();

    $response
        ->assertJsonCount(2, 'data.charges')
        ->assertJsonPath('data.charges.0.receipt.receipt_number', "REC-{$today}-001")
        ->assertJsonPath('data.charges.1.receipt.receipt_number', "REC-{$today}-002");

    // Discount is 20.00 = 2000 centavos. Cash's floored share: 2000 * 100/180 = 1111
    // (11.11). GCash, the last charge, takes the remainder: 889 (8.89).
    $cash = $response->json('data.charges.0.receipt.payload');
    $gcash = $response->json('data.charges.1.receipt.payload');

    expect($cash['subtotal'])->toBe(111.11)
        ->and($cash['discount'])->toBe(11.11)
        // toEqual, not toBe: JSON turns a whole-number float (100.0) into an int (100).
        ->and($cash['total'])->toEqual(100)
        ->and($cash['payment'])->toMatchArray(['method' => 'cash', 'tendered_amount' => 150, 'change_due' => 50])
        ->and($gcash['subtotal'])->toBe(88.89)
        ->and($gcash['discount'])->toBe(8.89)
        ->and($gcash['total'])->toEqual(80)
        ->and($gcash['payment']['reference'])->toBe('GC1')
        ->and($cash['cashier'])->toBe($cashier->name)
        ->and($cash['items'][0])->toMatchArray(['name' => 'Fried Itik', 'quantity' => 2, 'line_total' => 200.0]);

    expect(Receipt::count())->toBe(2)
        ->and(ReceiptPrint::where('is_reprint', false)->count())->toBe(2);
});

test('receipt discount shares always add up to the ticket discount to the centavo', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100), 1);
    app(TicketService::class)->SetDiscount($ticket, 10, 0); // total 90, split three ways of 30

    $paid = posPay($ticket, $cashier, [
        ['payment_method' => 'cash', 'amount' => 30],
        ['payment_method' => 'cash', 'amount' => 30],
        ['payment_method' => 'gcash', 'amount' => 30, 'payment_reference' => 'GC2'],
    ]);

    $payloads = $paid->charges->map(fn (Charge $charge) => $charge->receipt->payload);

    // 1000 centavos of discount over three equal charges: 333 + 333 + 334.
    expect($payloads->pluck('discount')->all())->toBe([3.33, 3.33, 3.34])
        ->and(round($payloads->sum('discount'), 2))->toBe(10.0)
        ->and(round($payloads->sum('subtotal'), 2))->toBe(100.0);

    foreach ($payloads as $payload) {
        expect(round($payload['subtotal'] - $payload['discount'], 2))->toEqual($payload['total']);
    }
});

test('kitchen notes never appear on a customer receipt', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');
    $line = posAddItem($ticket, posItem('Burger', 100), 1, 'Extra crispy, allergic to nuts');

    $paid = posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100]]);

    expect($line->fresh()->notes)->toBe('Extra crispy, allergic to nuts')
        ->and(json_encode($paid->charges->first()->receipt->payload))
        ->not->toContain('Extra crispy')
        ->not->toContain('allergic');
});

test('a receipt is a stored snapshot and does not change when the underlying data does', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100), 1);
    $paid = posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100]]);
    $receipt = $paid->charges->first()->receipt;

    TicketItem::query()->update(['item_name' => 'Renamed Later', 'line_total' => 1]);

    Sanctum::actingAs($cashier);

    $this->getJson("/api/v1/receipts/{$receipt->id}")
        ->assertOk()
        ->assertJsonPath('data.payload.items.0.name', 'Burger')
        ->assertJsonPath('data.payload.items.0.line_total', 100)
        ->assertJsonPath('data.payload.total', 100);
});

test('a charge total that is off by even one centavo is rejected', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100), 1);

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/tickets/{$ticket->id}/charges", [
        'charges' => [['payment_method' => 'cash', 'amount' => 100.01]],
    ])->assertStatus(409);

    expect($ticket->fresh()->status)->toBe('open')
        ->and(Charge::count())->toBe(0)
        ->and(Receipt::count())->toBe(0);
});

test('if receipt generation fails the whole payment rolls back', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100, quantity: 10);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $burger, 1);

    $failing = Mockery::mock(ReceiptService::class);
    $failing->shouldReceive('GenerateReceipts')->once()->andThrow(new RuntimeException('printer exploded'));
    app()->instance(ReceiptService::class, $failing);

    expect(fn () => app(PaymentService::class)->ChargeTicket($ticket, $cashier, [
        ['payment_method' => 'cash', 'amount' => 100],
    ]))->toThrow(RuntimeException::class, 'printer exploded');

    // Nothing half-happened: still open, no charge, stock still only reserved.
    expect($ticket->fresh()->status)->toBe('open')
        ->and(Charge::count())->toBe(0)
        ->and($burger->fresh()->quantity)->toBe(10)
        ->and($burger->fresh()->reserved_quantity)->toBe(1);
});

test('receipt numbers keep counting across tickets', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);

    foreach (['john', 'maria', 'pedro'] as $name) {
        $ticket = posTicket($shift, $cashier, $name);
        posAddItem($ticket, $burger, 1);
        posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100]]);
    }

    $today = now()->toDateString();

    expect(Receipt::orderBy('id')->pluck('receipt_number')->all())
        ->toBe(["REC-{$today}-001", "REC-{$today}-002", "REC-{$today}-003"]);
});

test('receipt history is paginated, filterable, searchable and visible to every terminal', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $burger = posItem('Burger', 100);

    $one = posTicket($shift, $cashier, 'john', 'POS-01');
    $two = posTicket($shift, $cashier, 'maria', 'POS-02');
    $three = posTicket($shift, $cashier, 'pedro', 'POS-01');
    foreach ([$one, $two, $three] as $ticket) {
        posAddItem($ticket, $burger, 1);
    }
    posPay($one, $cashier, [['payment_method' => 'cash', 'amount' => 100]]);
    posPay($two, $cashier, [['payment_method' => 'gcash', 'amount' => 100, 'payment_reference' => 'GC3']]);
    posPay($three, $cashier, [['payment_method' => 'cash', 'amount' => 100]]);

    // A different cashier on a different terminal still sees everything.
    Sanctum::actingAs(posUser());

    $this->getJson('/api/v1/receipts')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonMissingPath('data.0.payload');

    $this->getJson('/api/v1/receipts?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.current_page', 2);

    $this->getJson('/api/v1/receipts?payment_method=gcash')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'maria');

    $this->getJson('/api/v1/receipts?search=pedro')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', $three->order_number);

    $this->getJson('/api/v1/receipts?terminal_id=POS-01')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/receipts?ticket_id={$two->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/receipts?date_from='.now()->toDateString())->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/receipts?date_from='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/receipts?date_to='.now()->subDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');

    // Newest first.
    expect($this->getJson('/api/v1/receipts')->json('data.0.customer_name'))->toBe('pedro');
});

test('history filters are validated', function () {
    Sanctum::actingAs(posUser());

    $this->getJson('/api/v1/receipts?payment_method=bitcoin')->assertUnprocessable();
    $this->getJson('/api/v1/receipts?per_page=1000')->assertUnprocessable();
    $this->getJson('/api/v1/receipts?date_from=2026-09-10&date_to=2026-09-01')->assertUnprocessable();
});

test('reprinting logs a duplicate without creating a new receipt', function () {
    $cashier = posUser();
    $manager = posUser('manager');
    $shift = posOpenShift($cashier);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, posItem('Burger', 100), 1);
    $receipt = posPay($ticket, $cashier, [['payment_method' => 'cash', 'amount' => 100]])->charges->first()->receipt;

    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/receipts/{$receipt->id}/reprint")
        ->assertOk()
        ->assertJsonPath('data.is_reprint', true)
        ->assertJsonPath('data.watermark', 'DUPLICATE RECEIPT')
        ->assertJsonPath('data.receipt_number', $receipt->receipt_number)
        ->assertJsonPath('data.reprint_count', 1);

    $this->postJson("/api/v1/receipts/{$receipt->id}/reprint")
        ->assertOk()
        ->assertJsonPath('data.reprint_count', 2);

    expect(Receipt::count())->toBe(1);

    // History: the original print, then two duplicates, each attributed.
    $this->getJson("/api/v1/receipts/{$receipt->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data.prints')
        ->assertJsonPath('data.prints.0.is_reprint', false)
        ->assertJsonPath('data.prints.0.printed_by.name', $cashier->name)
        ->assertJsonPath('data.prints.1.is_reprint', true)
        ->assertJsonPath('data.prints.1.printed_by.name', $manager->name)
        ->assertJsonPath('data.reprint_count', 2);
});

test('receipt endpoints require authentication', function () {
    $this->getJson('/api/v1/receipts')->assertUnauthorized();
    $this->getJson('/api/v1/receipts/1')->assertUnauthorized();
    $this->postJson('/api/v1/receipts/1/reprint')->assertUnauthorized();
});

test('an unknown receipt is a clean 404', function () {
    Sanctum::actingAs(posUser());

    $this->getJson('/api/v1/receipts/999999')->assertNotFound()->assertJsonPath('success', false);
});
