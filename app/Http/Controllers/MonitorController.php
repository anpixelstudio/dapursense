<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\ReviewStatus;
use App\Enums\RunStatus;
use App\Enums\VideoStatus;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\MediaSource;
use App\Models\Property;
use App\Models\VideoAsset;
use App\Models\Zone;
use Inertia\Inertia;
use Inertia\Response;

class MonitorController extends Controller
{
    /**
     * CCTV wall grouped by property and kitchen. Upload sources have no live
     * feed, so each camera replays its latest ready recording; RTSP ingestion
     * is phase P1.
     */
    public function __invoke(): Response
    {
        $this->authorizePermission(Permission::ViewData);

        $sources = MediaSource::query()->where('status', 'active')->orderBy('name')
            ->get(['id', 'kitchen_id', 'name', 'type']);
        $sourceIds = $sources->pluck('id');

        $videos = VideoAsset::query()->whereIn('source_id', $sourceIds)
            ->where('status', VideoStatus::Ready)->whereNotNull('preview_key')
            ->orderByDesc('created_at')
            ->get(['id', 'source_id', 'original_name', 'duration_ms', 'width', 'height', 'recorded_at', 'created_at'])
            ->unique('source_id')->keyBy('source_id');

        $runs = AnalysisRun::query()->whereIn('video_asset_id', $videos->pluck('id'))
            ->where('status', RunStatus::Succeeded)
            ->with('model:id,name,version')
            ->orderByRaw("CASE WHEN mode = 'actual' THEN 0 ELSE 1 END")->orderByDesc('finished_at')
            ->get(['id', 'video_asset_id', 'model_id', 'mode', 'input_snapshot', 'finished_at'])
            ->unique('video_asset_id')->keyBy('video_asset_id');

        $zones = Zone::query()->whereIn('source_id', $sourceIds)->where('active', true)
            ->orderBy('name')->get(['id', 'source_id', 'name', 'type', 'points'])->groupBy('source_id');

        $events = AnomalyEvent::query()->whereIn('source_id', $sourceIds)
            ->orderByDesc('created_at')->limit(200)
            ->get(['id', 'source_id', 'run_id', 'type', 'category', 'severity', 'review_status', 'first_ms', 'is_simulation', 'created_at'])
            ->groupBy('source_id');

        $cameras = $sources->map(function (MediaSource $source) use ($videos, $runs, $zones, $events) {
            $video = $videos->get($source->id);
            $run = $video ? $runs->get($video->id) : null;
            $sourceEvents = $events->get($source->id, collect());

            return [
                'id' => $source->id,
                'kitchen_id' => $source->kitchen_id,
                'name' => $source->name,
                'type' => $source->type,
                'video' => $video,
                'run' => $run ? [
                    'id' => $run->id,
                    'mode' => $run->mode,
                    'model' => $run->model?->only(['name', 'version']),
                    'finished_at' => $run->finished_at,
                ] : null,
                'zones' => $run ? ($run->input_snapshot['zones'] ?? []) : $zones->get($source->id, collect())->values(),
                'open_events' => $sourceEvents->where('is_simulation', false)->whereIn('review_status', [ReviewStatus::Open, ReviewStatus::Acknowledged])->count(),
                'recent_events' => $sourceEvents->take(5)->values(),
            ];
        })->groupBy('kitchen_id');

        return Inertia::render('monitor/index', [
            'properties' => Property::query()->with(['kitchens' => fn ($q) => $q->orderBy('name')])->orderBy('name')
                ->get(['id', 'name', 'timezone'])
                ->map(fn (Property $property) => [
                    'id' => $property->id,
                    'name' => $property->name,
                    'timezone' => $property->timezone,
                    'rooms' => $property->kitchens->map(fn ($kitchen) => [
                        'id' => $kitchen->id,
                        'name' => $kitchen->name,
                        'cameras' => $cameras->get($kitchen->id, collect())->values(),
                    ])->values(),
                ]),
        ]);
    }
}
