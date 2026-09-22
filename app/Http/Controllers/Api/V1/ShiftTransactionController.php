<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftTransaction\CreateShiftTransactionRequest;
use App\Http\Requests\ShiftTransaction\UpdateShiftTransactionRequest;
use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Services\ShiftTransactionService;
use Illuminate\Http\JsonResponse;

class ShiftTransactionController extends Controller
{
    use ApiResponses;

    public function __construct(private ShiftTransactionService $shiftTransactionService) {}

    public function getTransactions(Shift $shift): JsonResponse
    {
        return $this->success(
            $shift->transactions()->whereNull('deleted_at')->latest()->get()
        );
    }

    public function createTransaction(CreateShiftTransactionRequest $request, Shift $shift): JsonResponse
    {
        $transaction = $this->shiftTransactionService->AddTransaction(
            $shift,
            $request->user(),
            $request->validated('type'),
            (float) $request->validated('amount'),
            $request->validated('reason'),
        );

        return $this->success($transaction, 201);
    }

    public function updateTransaction(UpdateShiftTransactionRequest $request, Shift $shift, ShiftTransaction $transaction): JsonResponse
    {
        $this->assertBelongsToShift($shift, $transaction);

        $updated = $this->shiftTransactionService->UpdateTransaction(
            $transaction,
            $request->user(),
            (float) $request->validated('amount'),
            $request->validated('reason'),
        );

        return $this->success($updated);
    }

    public function deleteTransaction(Shift $shift, ShiftTransaction $transaction): JsonResponse
    {
        $this->assertBelongsToShift($shift, $transaction);

        $this->shiftTransactionService->DeleteTransaction($transaction, auth()->user());

        return $this->success(['message' => 'Transaction deleted.']);
    }

    private function assertBelongsToShift(Shift $shift, ShiftTransaction $transaction): void
    {
        abort_if((int) $transaction->shift_id !== (int) $shift->id, 404);
    }
}
