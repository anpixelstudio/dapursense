<?php

namespace App\Http\Controllers;

use App\Domain\System\StorageMonitor;
use App\Domain\System\SystemStatus;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Enums\VideoStatus;
use App\Models\ActivitySegment;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\Device;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Models\PersonTrack;
use App\Models\PpeAssessment;
use App\Models\VideoAsset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(StorageMonitor $storage, SystemStatus $system): Response
    {
        $this->authorizePermission(Permission::ViewData);
        $tz = app(TenantContext::class)->tenant()->timezone;
        $since = CarbonImmutable::now($tz)->subDays(6)->startOfDay()->utc();

        $trend = AnomalyEvent::query()
            ->where('is_simulation', false)
            ->where('review_status', '!=', 'DISMISSED')
            ->where('created_at', '>=', $since)
            ->selectRaw("to_char(created_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, SUM(CASE WHEN category = 'violation' THEN 1 ELSE 0 END) AS violations, SUM(CASE WHEN category = 'violation' THEN 0 ELSE 1 END) AS reviews", [$tz])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = collect(range(6, 0))->map(fn ($i) => CarbonImmutable::now($tz)->subDays($i)->toDateString())
            ->map(fn ($d) => [
                'day' => $d,
                'violations' => (int) ($trend[$d]->violations ?? 0),
                'reviews' => (int) ($trend[$d]->reviews ?? 0),
            ]);

        // Re-running a video must not double count: only its latest actual run counts.
        $runs = AnalysisRun::query()
            ->where('mode', 'actual')
            ->where('status', RunStatus::Succeeded)
            ->where('finished_at', '>=', $since)
            ->orderByDesc('finished_at')
            ->get(['id', 'video_asset_id', 'source_id', 'finished_at'])
            ->unique('video_asset_id');
        $runIds = $runs->pluck('id');

        $people = PersonTrack::query()->whereIn('run_id', $runIds)
            ->selectRaw('run_id, COUNT(*) AS total')->groupBy('run_id')->pluck('total', 'run_id');
        $workMs = ActivitySegment::query()->whereIn('run_id', $runIds)
            ->whereNotNull('zone_id')->where('label', '!=', 'in_restricted_zone')
            ->selectRaw('run_id, SUM(end_ms - start_ms) AS ms')->groupBy('run_id')->pluck('ms', 'run_id');

        $ppe = PpeAssessment::query()
            ->where('required', true)
            ->whereIn('run_id', $runIds)
            ->selectRaw('state, SUM(end_ms - start_ms) AS ms')
            ->groupBy('state')
            ->pluck('ms', 'state');

        $assessable = (int) ($ppe['present'] ?? 0) + (int) ($ppe['missing'] ?? 0);
        $relevant = $assessable + (int) ($ppe['unknown'] ?? 0);

        $openEvents = AnomalyEvent::query()->where('is_simulation', false)->whereIn('review_status', ['OPEN', 'ACKNOWLEDGED']);

        return Inertia::render('dashboard', [
            'stats' => [
                'needs_review' => (clone $openEvents)->count(),
                'needs_review_simulation' => AnomalyEvent::query()->where('is_simulation', true)->whereIn('review_status', ['OPEN', 'ACKNOWLEDGED'])->count(),
                'people' => (int) $people->sum(),
                'work_zone_ms' => (int) $workMs->sum(),
                'restricted' => AnomalyEvent::query()->where('is_simulation', false)->where('type', 'restricted_zone')->where('created_at', '>=', $since)->count(),
                'analysed_videos' => $runs->count(),
                'ppe_compliance' => $assessable > 0 ? round(($ppe['present'] ?? 0) / $assessable, 4) : null,
                'ppe_coverage' => $relevant > 0 ? round($assessable / $relevant, 4) : null,
            ],
            'trend' => $days,
            'rooms' => $this->rooms($runs, $people, $workMs, (clone $openEvents)->selectRaw('source_id, COUNT(*) AS total')->groupBy('source_id')->pluck('total', 'source_id')),
            'recentEvents' => AnomalyEvent::query()->with('source:id,name')->whereIn('review_status', ['OPEN', 'ACKNOWLEDGED'])
                ->orderBy('is_simulation')->orderByDesc('created_at')->limit(8)
                ->get(['id', 'type', 'category', 'severity', 'review_status', 'source_id', 'first_ms', 'is_simulation', 'evidence', 'created_at']),
            'activeRuns' => AnalysisRun::query()->whereIn('status', ['QUEUED', 'RUNNING'])
                ->with('video:id,original_name')->orderBy('created_at')
                ->get(['id', 'status', 'mode', 'progress', 'phase', 'video_asset_id', 'created_at']),
            'storage' => ['used_percent' => $storage->usedPercent(), 'warning' => $storage->isWarning(), 'blocked' => $storage->isBlocked()],
            'health' => Inertia::defer(fn () => $this->health($system)),
        ]);
    }

    /**
     * @param  Collection<int, AnalysisRun>  $runs
     * @param  Collection<string, int>  $people
     * @param  Collection<string, int>  $workMs
     * @param  Collection<string, int>  $openBySource
     * @return list<array<string, mixed>>
     */
    private function rooms($runs, $people, $workMs, $openBySource): array
    {
        $sources = MediaSource::query()->where('status', 'active')->get(['id', 'kitchen_id']);
        $online = VideoAsset::query()->whereIn('source_id', $sources->pluck('id'))
            ->where('status', VideoStatus::Ready)->whereNotNull('preview_key')
            ->distinct()->pluck('source_id')->flip();
        $runsBySource = $runs->groupBy('source_id');

        return Kitchen::query()->with('property:id,name')->orderBy('name')->get(['id', 'name', 'property_id'])
            ->map(function (Kitchen $kitchen) use ($sources, $online, $runsBySource, $people, $workMs, $openBySource) {
                $ids = $sources->where('kitchen_id', $kitchen->id)->pluck('id');
                $roomRuns = $ids->flatMap(fn ($id) => $runsBySource->get($id, collect()));

                return [
                    'id' => $kitchen->id,
                    'name' => $kitchen->name,
                    'property' => $kitchen->property?->name,
                    'cameras' => $ids->count(),
                    'online' => $ids->filter(fn ($id) => $online->has($id))->count(),
                    'people' => (int) $roomRuns->sum(fn ($run) => $people[$run->id] ?? 0),
                    'work_zone_ms' => (int) $roomRuns->sum(fn ($run) => $workMs[$run->id] ?? 0),
                    'open_events' => (int) $ids->sum(fn ($id) => $openBySource[$id] ?? 0),
                    'last_analysis_at' => $roomRuns->max('finished_at'),
                ];
            })
            ->sortBy([['property', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function health(SystemStatus $system): array
    {
        $status = $system->collect();

        return [
            'database' => $status['services']['database'],
            'redis' => $status['services']['redis'],
            'worker' => (bool) ($status['services']['worker']['ok'] ?? false),
            'analysis_ready' => $status['analysis_ready'],
            'storage_used_percent' => $status['services']['storage_used_percent'],
            'storage_warning' => $status['services']['storage_warning'],
            'storage_blocked' => $status['services']['storage_blocked'],
            'active_sources' => MediaSource::query()->where('status', 'active')->count(),
            'queued_runs' => AnalysisRun::query()->where('status', 'QUEUED')->count(),
            'running_runs' => AnalysisRun::query()->where('status', 'RUNNING')->count(),
            'devices' => (array) Device::query()->where('check_type', '!=', 'system')->where('paused', false)
                ->toBase()
                ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'up') AS up, COUNT(*) FILTER (WHERE status = 'down') AS down")
                ->first(),
        ];
    }
}
