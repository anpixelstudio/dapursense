<?php

namespace App\Domain\System;

use Illuminate\Support\Facades\Storage;

class StorageMonitor
{
    public function usedPercent(): float
    {
        $path = Storage::disk(config('kitchen.storage_disk'))->path('');
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;

        return $total > 0 ? round((1 - $free / $total) * 100, 1) : 0.0;
    }

    public function isBlocked(): bool
    {
        return $this->usedPercent() >= config('kitchen.storage.block_percent');
    }

    public function isWarning(): bool
    {
        return $this->usedPercent() >= config('kitchen.storage.warn_percent');
    }
}
