<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesBackOffice;

class UserPolicy
{
    use AuthorizesBackOffice;

    // Admin accounts are protected from being edited/deleted at all, regardless of the
    // actor's own role — that rule lives in UserController/routes/web.php (abort_if), not
    // here, because it depends on the *target* user's role, not the actor's.
}
