<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMedia extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'event_media';

    protected $fillable = [
        'tenant_id', 'event_id', 'kind', 'disk', 'key', 'state', 'start_ms', 'end_ms',
        'checksum', 'expires_at', 'error_code',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AnomalyEvent::class, 'event_id');
    }
}
