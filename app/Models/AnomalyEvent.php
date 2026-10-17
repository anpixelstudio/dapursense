<?php

namespace App\Models;

use App\Enums\EventCategory;
use App\Enums\ReviewStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnomalyEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'events';

    protected $fillable = [
        'tenant_id', 'run_id', 'source_id', 'track_id', 'type', 'category', 'severity', 'lifecycle',
        'review_status', 'revision', 'last_reviewed_by', 'last_reviewed_at', 'first_ms', 'last_ms',
        'occurred_at', 'peak_score', 'evidence', 'evidence_hold', 'is_simulation', 'dedupe_key',
    ];

    protected $attributes = ['review_status' => 'OPEN', 'revision' => 1, 'evidence' => '{}'];

    protected function casts(): array
    {
        return [
            'category' => EventCategory::class,
            'review_status' => ReviewStatus::class,
            'evidence' => 'array',
            'evidence_hold' => 'boolean',
            'is_simulation' => 'boolean',
            'occurred_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'peak_score' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class, 'run_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class, 'source_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(PersonTrack::class, 'track_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(EventReview::class, 'event_id')->orderBy('created_at');
    }

    public function media(): HasMany
    {
        return $this->hasMany(EventMedia::class, 'event_id');
    }

    public function lastReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by');
    }
}
