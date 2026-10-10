<?php

namespace App\Services;

use App\Exceptions\NoActiveShiftException;
use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Models\User;
use App\Services\Sync\SyncContext;

class ShiftTransactionService
{
    /**
     * Offline ($sync->offline) the cash already left or entered the drawer: the entry is dated
     * when it happened and still lands on its shift if that closed meanwhile (SyncService flags it).
     */
    public function AddTransaction(Shift $shift, User $createdBy, string $type, float $amount, string $reason, ?string $clientUuid = null, ?SyncContext $sync = null): ShiftTransaction
    {
        $offline = $sync?->offline === true;

        if (! $shift->isOpen() && ! $offline) {
            throw new NoActiveShiftException('Cannot add a transaction to a closed shift.');
        }

        $transaction = new ShiftTransaction([
            'client_uuid' => $clientUuid,
            'shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'created_by' => $createdBy->id,
            'synced_at' => $offline ? now() : null,
        ]);

        if ($offline) {
            $transaction->created_at = $sync->at;
        }

        $transaction->save();

        return $transaction;
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
