<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\ReceiptPrint;
use App\Services\ReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A receipt exactly as printed (its stored payload), opened from a ticket's payments, with its
 * print history. Printing from the back office is a duplicate, logged the same way as a POS
 * reprint.
 */
class ReceiptController extends Controller
{
    public function __construct(private ReceiptService $receiptService) {}

    public function getReceipt(Receipt $receipt): Response
    {
        $receipt = $this->receiptService->ReadReceipt($receipt)->load('issuedBy:id,name');

        return Inertia::render('ReceiptPage', [
            'receipt' => [
                'id' => $receipt->id,
                'ticket_id' => $receipt->ticket_id,
                'receipt_number' => $receipt->receipt_number,
                'issued_at' => $receipt->issued_at,
                'issued_by' => $receipt->issuedBy?->name,
                'payload' => $receipt->payload,
            ],
            'prints' => $receipt->prints->map(fn (ReceiptPrint $print): array => [
                'id' => $print->id,
                'is_reprint' => $print->is_reprint,
                'printed_at' => $print->printed_at,
                'printed_by' => $print->printedBy?->name,
            ])->values(),
        ]);
    }

    public function reprintReceipt(Request $request, Receipt $receipt): RedirectResponse
    {
        $this->receiptService->ReprintReceipt($receipt, $request->user());

        return back();
    }
}
