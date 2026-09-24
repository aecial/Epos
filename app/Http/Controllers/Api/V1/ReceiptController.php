<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receipt\GetReceiptsRequest;
use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    use ApiResponses;

    public function __construct(private ReceiptService $receiptService) {}

    public function getReceipts(GetReceiptsRequest $request): JsonResponse
    {
        $page = $this->receiptService->ReadAllReceipt(
            $request->safe()->except(['page', 'per_page']),
            (int) $request->validated('per_page', 20),
        );

        return $this->success($page->items(), meta: [
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function getReceipt(Receipt $receipt): JsonResponse
    {
        return $this->success($this->receiptService->ReadReceipt($receipt));
    }

    public function reprintReceipt(Request $request, Receipt $receipt): JsonResponse
    {
        $reprinted = $this->receiptService->ReprintReceipt($receipt, $request->user());

        // The stored receipt is never altered. `is_reprint` + `watermark` tell the POS to
        // print this copy as a duplicate.
        return $this->success(array_merge($reprinted->toArray(), [
            'is_reprint' => true,
            'watermark' => 'DUPLICATE RECEIPT',
        ]));
    }
}
