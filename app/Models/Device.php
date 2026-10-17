<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'kitchen_id', 'source_id', 'name', 'kind', 'check_type', 'host', 'port', 'target',
        'interval_seconds', 'failure_threshold', 'paused', 'status', 'consecutive_failures', 'last_latency_ms',
        'last_error', 'last_checked_at', 'last_seen_at', 'status_changed_at', 'heartbeat_token_hash', 'meta',
    ];

    protected $hidden = ['heartbeat_token_hash'];

    protected function casts(): array
    {
        return [
            'paused' => 'boolean',
            'meta' => 'array',
            'last_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(Kitchen::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class, 'source_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(DeviceIncident::class);
    }
}
