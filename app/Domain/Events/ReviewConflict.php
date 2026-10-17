<?php

namespace App\Domain\Events;

use App\Models\AnomalyEvent;
use RuntimeException;

class ReviewConflict extends RuntimeException
{
    public function __construct(public readonly AnomalyEvent $current)
    {
        parent::__construct('Kejadian sudah diubah reviewer lain.');
    }
}
