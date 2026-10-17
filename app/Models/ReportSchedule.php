<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportSchedule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'created_by', 'frequency', 'format', 'timezone', 'filters', 'recipients',
        'next_run_at', 'last_period_key_at', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipients' => 'array',
            'next_run_at' => 'datetime',
            'last_period_key_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }
}
