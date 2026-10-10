<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A phone still holds offline sales the server hasn't received, so the drawer can't be counted
 * against the right total yet.
 */
class UnsyncedDevicesException extends RuntimeException
{
    public function __construct(string $message = 'A device still has offline actions waiting to sync.')
    {
        parent::__construct($message);
    }
}
