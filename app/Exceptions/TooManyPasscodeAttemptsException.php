<?php

namespace App\Exceptions;

use RuntimeException;

class TooManyPasscodeAttemptsException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Too many incorrect passcode attempts. Please try again in {$retryAfter} seconds.");
    }
}
