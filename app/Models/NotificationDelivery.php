<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'report_id', 'channel', 'destination_ref', 'dedupe_key',
        'status', 'attempts', 'next_retry_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['next_retry_at' => 'datetime'];
    }
}
