<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoAsset extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'source_id', 'uploaded_by', 'original_name', 'original_disk', 'original_key',
        'preview_key', 'checksum', 'bytes', 'duration_ms', 'width', 'height', 'fps', 'codec', 'rotation',
        'recorded_at', 'recorded_timezone', 'status', 'error_code', 'error_message',
        'last_run_finished_at', 'original_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'recorded_at' => 'datetime',
            'last_run_finished_at' => 'datetime',
            'original_expires_at' => 'datetime',
            'fps' => 'float',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class, 'source_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }

    public function directory(): string
    {
        return "tenants/{$this->tenant_id}/videos/{$this->id}";
    }

    public function referenceFrameKey(): string
    {
        return $this->directory().'/reference.jpg';
    }

    public function canReanalyze(): bool
    {
        return $this->status === VideoStatus::Ready && $this->original_key !== null;
    }
}
