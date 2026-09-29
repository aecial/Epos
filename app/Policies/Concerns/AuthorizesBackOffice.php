<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Every back-office management resource today shares one rule: admin/manager may view,
 * create, update and delete it; a cashier may do none of that (CLAUDE.md's role matrix —
 * "Manage Menu/Ingredients/Users"). Concrete policies compose this instead of repeating the
 * same five one-line checks.
 */
trait AuthorizesBackOffice
{
    public function viewAny(User $user): bool
    {
        return $user->isAdminOrManager();
    }

    public function view(User $user): bool
    {
        return $user->isAdminOrManager();
    }

    public function create(User $user): bool
    {
        return $user->isAdminOrManager();
    }

    public function update(User $user): bool
    {
        return $user->isAdminOrManager();
    }

    public function delete(User $user): bool
    {
        return $user->isAdminOrManager();
    }
}
