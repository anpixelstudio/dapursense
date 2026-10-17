<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonTrack extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'run_id', 'track_key', 'first_ms', 'last_ms'];

    public function activitySegments(): HasMany
    {
        return $this->hasMany(ActivitySegment::class, 'track_id');
    }

    public function ppeAssessments(): HasMany
    {
        return $this->hasMany(PpeAssessment::class, 'track_id');
    }
}
