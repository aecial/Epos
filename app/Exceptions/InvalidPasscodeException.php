<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPasscodeException extends RuntimeException
{
    public function __construct(string $message = 'Passcode is invalid or the approver lacks permission.')
    {
        parent::__construct($message);
    }
}
