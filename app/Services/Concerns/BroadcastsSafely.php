<?php

namespace App\Services\Concerns;

trait BroadcastsSafely
{
    /**
     * A Reverb outage must never turn an already-committed mutation into a failed response -
     * the tablet falls back to polling GET /kds/orders when a live push doesn't arrive.
     */
    private function broadcastSafely(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
