<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesBackOffice;

class ShiftPolicy
{
    use AuthorizesBackOffice;
}
