<?php

namespace App\Services\Sync;

use App\Models\PosDevice;
use Carbon\CarbonImmutable;

/**
 * Who sent a synced action, and whether it happened while the phone was offline. An offline
 * action already happened in the real world (the food was served, the money taken), so the
 * services record it at the time it happened and accept what they would otherwise refuse:
 * stock may go below zero, the price the phone charged stands, and a closed shift still takes
 * its late sales. Everything accepted that way is raised as a sync issue by SyncService.
 */
final readonly class SyncContext
{
    public function __construct(
        public PosDevice $device,
        public bool $offline,
        public CarbonImmutable $at,
    ) {}
}
