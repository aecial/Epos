<?php

namespace App\Exceptions;

use RuntimeException;

class ChargeAmountMismatchException extends RuntimeException
{
    public function __construct(string $message = 'Sum of charges does not equal ticket total.')
    {
        parent::__construct($message);
    }
}
