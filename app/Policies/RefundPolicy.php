<?php

namespace App\Policies;

use App\Models\User;

class RefundPolicy
{
    /**
     * Back-office refund history (view only): admin/manager. Refunds are requested, approved
     * and rejected on the POS through the API, which doesn't use this policy.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdminOrManager();
    }
}
