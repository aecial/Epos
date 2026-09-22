<?php

namespace App\Exceptions;

use RuntimeException;

class ActiveShiftExistsException extends RuntimeException
{
    public function __construct(string $message = 'A shift is already open.')
    {
        parent::__construct($message);
    }
}
