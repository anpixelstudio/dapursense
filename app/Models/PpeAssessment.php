<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PpeAssessment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'run_id', 'track_id', 'item_code', 'required', 'state',
        'start_ms', 'end_ms', 'score', 'visibility', 'reason',
    ];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'score' => 'float', 'visibility' => 'float'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(PersonTrack::class, 'track_id');
    }
}
