<?php

namespace App\Exceptions;

use RuntimeException;

class OpenTicketsExistException extends RuntimeException
{
    public function __construct(string $message = 'Shift has open tickets that must be paid or cancelled first.')
    {
        parent::__construct($message);
    }
}
