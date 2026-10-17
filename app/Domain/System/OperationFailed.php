<?php

namespace App\Domain\System;

use RuntimeException;

class OperationFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $exitCode = 1)
    {
        parent::__construct($message);
    }
}
