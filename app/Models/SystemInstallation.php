<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SystemInstallation extends Model
{
    use HasUuids;

    protected $fillable = [
        'target_version', 'operation', 'status', 'last_step', 'report_key', 'manifest',
        'error_code', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
