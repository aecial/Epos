<?php

namespace App\Policies;

use App\Models\User;

class ReceiptPolicy
{
    /**
     * Back-office receipt view and duplicate printing: admin/manager. The POS reads and reprints
     * receipts through the API, which every role may use.
     */
    public function view(User $user): bool
    {
        return $user->isAdminOrManager();
    }
}
