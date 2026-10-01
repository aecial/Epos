<?php

use Illuminate\Support\Facades\Broadcast;

// Any authenticated Sanctum token may subscribe - same rule the rest of the API already
// applies to ticket/menu data. auth:sanctum on the broadcasting/auth route (bootstrap/app.php)
// guarantees $user is non-null here.
Broadcast::channel('kds.orders', fn ($user) => (bool) $user);
