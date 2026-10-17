<?php

namespace App\Domain\Analysis;

use App\Domain\System\ReleaseManifest;
use App\Enums\RunStatus;
use App\Models\AiModel;
use App\Models\AnalysisRun;
use App\Models\RuleSet;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VideoAsset;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AnalysisService
{
    public function __construct(private ReleaseManifest $release, private WorkerClient $worker) {}

    public function start(VideoAsset $video, AiModel $model, RuleSet $ruleSet, string $mode, User $actor): AnalysisRun
    {
        if (SystemSetting::get('analysis.paused', false)) {
            throw ValidationException::withMessages(['mode' => 'Analysis is paused during a system upgrade. Try again after the upgrade completes.']);
        }

        if (! $video->canReanalyze()) {
            throw ValidationException::withMessages(['video' => 'Video is not ready or the original file has expired; re-analysis is unavailable.']);
        }

        if ($mode === 'simulation' && ! $model->isSimulator()) {
            throw ValidationException::withMessages(['model_id' => 'Simulation mode may only use the simulator model.']);
        }

        if ($mode === 'actual' && $model->isSimulator()) {
            throw ValidationException::withMessages(['model_id' => 'The simulator model cannot be used for real analysis.']);
        }

        $revision = $ruleSet->activeRevision ?? throw ValidationException::withMessages(['rule_set_id' => 'Rule set has no active revision.']);

        $zones = Zone::query()->where('source_id', $video->source_id)->where('active', true)->get();

        return DB::transaction(function () use ($video, $model, $revision, $mode, $actor, $zones) {
            $run = new AnalysisRun([
                'tenant_id' => $video->tenant_id,
                'source_id' => $video->source_id,
                'video_asset_id' => $video->id,
                'model_id' => $model->id,
                'rule_revision_id' => $revision->id,
                'requested_by' => $actor->id,
                'mode' => $mode,
                'status' => RunStatus::Queued,
                'phase' => 'queued',
                'app_version' => $this->release->version(),
                'model_sha256' => $model->sha256,
                'rules_sha256' => $revision->checksum,
            ]);
            $run->id = (string) str()->uuid();
            $run->input_snapshot = [
                'contract_version' => config('kitchen.worker.contract_version'),
                'mode' => $mode,
                'video' => [
                    'id' => $video->id,
                    'key' => $video->preview_key,
                    'duration_ms' => $video->duration_ms,
                    'width' => $video->width,
                    'height' => $video->height,
                    'fps' => $video->fps,
                    'rotation_applied' => $video->rotation,
                    'recorded_at' => $video->recorded_at?->toIso8601String(),
                    'recorded_timezone' => $video->recorded_timezone,
                ],
                'zones' => $zones->map(fn (Zone $z) => [
                    'id' => $z->id,
                    'name' => $z->name,
                    'type' => $z->type->value,
                    'revision' => $z->revision,
                    'points' => $z->points,
                ])->values()->all(),
                'rules' => [
                    'revision_id' => $revision->id,
                    'version' => $revision->version,
                    'checksum' => $revision->checksum,
                    'config' => $revision->config,
                ],
                'model' => $model->only(['id', 'name', 'version', 'runtime', 'artifact_key', 'capabilities', 'labels', 'sha256']),
                'evidence' => config('kitchen.evidence'),
                'output_dir' => $run->directory(),
            ];
            $run->save();

            DispatchRunJob::dispatch($run->id)->afterCommit();

            return $run;
        });
    }

    public function cancel(AnalysisRun $run): void
    {
        DB::transaction(function () use ($run) {
            $locked = AnalysisRun::query()->whereKey($run->id)->lockForUpdate()->first();

            if ($locked->status === RunStatus::Queued) {
                $locked->update(['status' => RunStatus::Cancelled, 'phase' => 'cancelled', 'finished_at' => now()]);
            } elseif ($locked->status === RunStatus::Running) {
                $locked->update(['cancel_requested_at' => now(), 'phase' => 'cancelling']);
            }
        });

        if ($run->fresh()->status === RunStatus::Running) {
            $this->worker->cancel($run->id);
        }
    }

    /** Absolute path the worker uses as storage root (shared private volume). */
    public static function storageRoot(): string
    {
        return rtrim(Storage::disk(config('kitchen.storage_disk'))->path(''), '/');
    }
}
