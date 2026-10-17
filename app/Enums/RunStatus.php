<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
