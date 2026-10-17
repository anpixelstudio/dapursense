<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivitySegment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'run_id', 'track_id', 'zone_id', 'label', 'start_ms', 'end_ms', 'score', 'evidence'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'score' => 'float'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(PersonTrack::class, 'track_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
