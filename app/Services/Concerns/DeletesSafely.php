<?php

namespace App\Services\Concerns;

use App\Exceptions\RecordInUseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

trait DeletesSafely
{
    /**
     * Wraps $model->delete() so a foreign-key-constraint violation (SQLSTATE 23000, from a
     * restrictOnDelete() relation protecting historical/related records) becomes a clean,
     * catchable error instead of an unhandled QueryException surfacing as a 500.
     */
    private function deleteOrFail(Model $model, string $inUseMessage): bool
    {
        try {
            return (bool) $model->delete();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw new RecordInUseException($inUseMessage);
            }

            throw $e;
        }
    }
}
