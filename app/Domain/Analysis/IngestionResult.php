<?php

namespace App\Domain\Analysis;

class IngestionResult
{
    /** @param array<string, mixed> $body */
    public function __construct(public int $status, public array $body) {}

    /** @param array<string, mixed> $extra */
    public static function conflict(string $code, string $message, array $extra = []): self
    {
        return new self(409, ['error' => $code, 'message' => $message] + $extra);
    }

    /** @param array<string, mixed> $body */
    public static function ok(array $body = []): self
    {
        return new self(200, ['ok' => true] + $body);
    }
}
