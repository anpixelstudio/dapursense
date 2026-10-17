<?php

namespace App\Domain\Video;

use RuntimeException;

class VideoRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
