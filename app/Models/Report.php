<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'requested_by', 'format', 'status', 'filters', 'cutoff_at', 'manifest',
        'disk', 'key', 'checksum', 'completed_at', 'expires_at', 'error_code',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'manifest' => 'array',
            'cutoff_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
