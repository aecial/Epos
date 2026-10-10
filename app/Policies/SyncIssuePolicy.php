<?php

namespace App\Policies;

use App\Models\User;

class SyncIssuePolicy
{
    /**
     * The Sync review page: what offline sales the server accepted but a manager should check.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdminOrManager();
    }

    /**
     * Marking an issue as looked at. Nothing about the sale changes; it only leaves the list.
     */
    public function review(User $user): bool
    {
        return $user->isAdminOrManager();
    }
}
