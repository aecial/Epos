<?php

namespace App\Services;

use App\Exceptions\NoActiveShiftException;
use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Models\User;

class ShiftTransactionService
{
    public function AddTransaction(Shift $shift, User $createdBy, string $type, float $amount, string $reason): ShiftTransaction
    {
        if (! $shift->isOpen()) {
            throw new NoActiveShiftException('Cannot add a transaction to a closed shift.');
        }

        return ShiftTransaction::create([
            'shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $createdBy->id,
        ]);
    }

    public function UpdateTransaction(ShiftTransaction $transaction, User $updatedBy, float $amount, string $reason): ShiftTransaction
    {
        if (! $transaction->shift->isOpen()) {
            throw new NoActiveShiftException('Cannot edit a transaction on a closed shift.');
        }

        $transaction->update([
            'amount' => $amount,
            'reason' => $reason,
            'updated_by' => $updatedBy->id,
        ]);

        return $transaction;
    }

    public function DeleteTransaction(ShiftTransaction $transaction, User $deletedBy): void
    {
        if (! $transaction->shift->isOpen()) {
            throw new NoActiveShiftException('Cannot delete a transaction on a closed shift.');
        }

        $transaction->update([
            'deleted_by' => $deletedBy->id,
            'deleted_at' => now(),
        ]);
    }
}
