<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisRun extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'source_id', 'video_asset_id', 'model_id', 'rule_revision_id', 'requested_by',
        'mode', 'status', 'phase', 'progress', 'processed_ms', 'input_snapshot', 'app_version',
        'model_sha256', 'rules_sha256', 'worker_token_hash', 'attempt', 'last_batch_sequence',
        'heartbeat_at', 'cancel_requested_at', 'error_code', 'error_message', 'started_at', 'finished_at',
        'result_manifest',
    ];

    protected $hidden = ['worker_token_hash'];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'input_snapshot' => 'array',
            'result_manifest' => 'array',
            'heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isSimulation(): bool
    {
        return $this->mode === 'simulation';
    }

    public function directory(): string
    {
        return "tenants/{$this->tenant_id}/runs/{$this->id}";
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class, 'source_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoAsset::class, 'video_asset_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function ruleRevision(): BelongsTo
    {
        return $this->belongsTo(RuleRevision::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(PersonTrack::class, 'run_id');
    }

    public function activitySegments(): HasMany
    {
        return $this->hasMany(ActivitySegment::class, 'run_id');
    }

    public function ppeAssessments(): HasMany
    {
        return $this->hasMany(PpeAssessment::class, 'run_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AnomalyEvent::class, 'run_id');
    }
}
