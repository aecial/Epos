<?php

use App\Models\ReceiptPrint;
use App\Models\Ticket;

/*
| Back-office receipt view: GET /receipts/{receipt} renders the stored payload exactly as issued,
| with its print history; POST /receipts/{receipt}/reprint logs a duplicate, the same as a POS
| reprint. Admin/manager only. Opened from a ticket's payments.
*/

// These assert the Inertia props; the page component itself is compiled by Vite.
beforeEach(fn () => $this->withoutVite());

/** A ticket split across cash and GCash, so it has two receipts. */
function splitPaidTicket(): Ticket
{
    $cashier = posUser();
    $ticket = posTicket(posOpenShift($cashier), $cashier, 'maria');
    posAddItem($ticket, posItem('Fried Itik', 295), 2, 'extra crispy');

    return posPay($ticket->fresh(), $cashier, [
        ['payment_method' => 'cash', 'amount' => 290, 'tendered_amount' => 300],
        ['payment_method' => 'gcash', 'amount' => 300, 'payment_reference' => 'GC-2'],
    ]);
}

test('a manager sees a receipt exactly as issued, with its original print logged', function () {
    $manager = posUser('manager');
    $ticket = splitPaidTicket();
    $receipt = $ticket->receipts()->where('payment_method', 'gcash')->firstOrFail();

    $this->actingAs($manager)
        ->get("/receipts/{$receipt->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ReceiptPage')
            ->where('receipt.id', $receipt->id)
            ->where('receipt.ticket_id', $ticket->id)
            ->where('receipt.receipt_number', $receipt->receipt_number)
            ->where('receipt.payload.order.customer_name', 'maria')
            ->where('receipt.payload.payment.method', 'gcash')
            ->where('receipt.payload.payment.reference', 'GC-2')
            ->where('receipt.payload.total', 300)
            ->where('receipt.payload.items.0.name', 'Fried Itik')
            ->missing('receipt.payload.items.0.notes')
            ->has('prints', 1)
            ->where('prints.0.is_reprint', false)
        );
});

test('printing from the back office logs a duplicate by the manager', function () {
    $manager = posUser('manager');
    $receipt = splitPaidTicket()->receipts()->firstOrFail();

    $this->actingAs($manager)
        ->from("/receipts/{$receipt->id}")
        ->post("/receipts/{$receipt->id}/reprint")
        ->assertRedirect("/receipts/{$receipt->id}");

    expect(ReceiptPrint::where('receipt_id', $receipt->id)->where('is_reprint', true)->pluck('printed_by')->all())->toBe([$manager->id]);

    $this->actingAs($manager)
        ->get("/receipts/{$receipt->id}")
        ->assertInertia(fn ($page) => $page
            ->has('prints', 2)
            ->where('prints.1.is_reprint', true)
            ->where('prints.1.printed_by', $manager->name)
        );
});

test('a cashier can neither view nor print a receipt here; a guest is sent to login', function () {
    $cashier = posUser();
    $receipt = splitPaidTicket()->receipts()->firstOrFail();

    $this->actingAs($cashier)->get("/receipts/{$receipt->id}")->assertRedirect('/login');
    $this->actingAs($cashier)->post("/receipts/{$receipt->id}/reprint")->assertRedirect('/login');
    expect(ReceiptPrint::where('receipt_id', $receipt->id)->where('is_reprint', true)->count())->toBe(0);

    auth()->logout();
    $this->get("/receipts/{$receipt->id}")->assertRedirect('/login');
});
