<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\EventMedia;
use App\Models\Report;
use App\Models\VideoAsset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes files first, then marks metadata, item by item, so an interrupted run
 * can resume. Active runs and evidence holds always block cleanup.
 */
#[Signature('retention:cleanup')]
#[Description('Delete files past retention policy (original video, observations, evidence, reports)')]
class RetentionCleanup extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk(config('kitchen.storage_disk'));
        $counts = ['videos' => 0, 'observations' => 0, 'media' => 0, 'reports' => 0];

        VideoAsset::query()->withoutGlobalScopes()
            ->whereNotNull('original_key')
            ->where('original_expires_at', '<', now())
            ->whereDoesntHave('runs', fn ($q) => $q->withoutGlobalScopes()->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value]))
            ->whereNotIn('id', AnalysisRun::query()->withoutGlobalScopes()
                ->whereIn('id', AnomalyEvent::query()->withoutGlobalScopes()->where('evidence_hold', true)->select('run_id'))
                ->whereNotNull('video_asset_id')->select('video_asset_id'))
            ->each(function (VideoAsset $video) use ($disk, &$counts) {
                $disk->delete(array_filter([$video->original_key, $video->preview_key]));
                $video->update(['original_key' => null, 'preview_key' => null]);
                $counts['videos']++;
            });

        AnalysisRun::query()->withoutGlobalScopes()
            ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value, RunStatus::Cancelled->value])
            ->where('finished_at', '<', now()->subDays(config('kitchen.retention_days.observations')))
            ->where(fn ($q) => $q->whereNull('result_manifest')->orWhereRaw("COALESCE((result_manifest->>'observations_expired')::boolean, false) = false"))
            ->whereNotIn('id', AnomalyEvent::query()->withoutGlobalScopes()->where('evidence_hold', true)->whereNotNull('run_id')->select('run_id'))
            ->each(function (AnalysisRun $run) use ($disk, &$counts) {
                $disk->deleteDirectory($run->directory().'/observations');
                $run->update(['result_manifest' => [...($run->result_manifest ?? []), 'observations_expired' => true]]);
                $counts['observations']++;
            });

        EventMedia::query()->withoutGlobalScopes()
            ->where('state', 'READY')
            ->where('expires_at', '<', now())
            ->whereHas('event', fn ($q) => $q->withoutGlobalScopes()->where('evidence_hold', false))
            ->each(function (EventMedia $media) use ($disk, &$counts) {
                if ($media->key) {
                    $disk->delete($media->key);
                }
                $media->update(['state' => 'EXPIRED']);
                $counts['media']++;
            });

        Report::query()->withoutGlobalScopes()
            ->where('status', 'READY')
            ->where('expires_at', '<', now())
            ->each(function (Report $report) use ($disk, &$counts) {
                if ($report->key) {
                    $disk->delete($report->key);
                }
                $report->update(['status' => 'EXPIRED']);
                $counts['reports']++;
            });

        $counts['device_stats'] = DB::table('device_stats_hourly')
            ->where('hour', '<', now()->subDays(config('kitchen.uptime.stats_retention_days')))
            ->delete();

        $this->table(['Kind', 'Deleted'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values());

        return self::SUCCESS;
    }
}
