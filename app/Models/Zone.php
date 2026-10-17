<?php

namespace App\Models;

use App\Enums\ZoneType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Zone extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'source_id', 'name', 'type', 'revision', 'points', 'geometry_snapshot', 'active'];

    protected function casts(): array
    {
        return [
            'type' => ZoneType::class,
            'points' => 'array',
            'geometry_snapshot' => 'array',
            'active' => 'boolean',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class, 'source_id');
    }
}
