<?php

namespace App\Exceptions;

use RuntimeException;

class NoActiveShiftException extends RuntimeException
{
    public function __construct(string $message = 'No active shift is open.')
    {
        parent::__construct($message);
    }
}
