<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Controllers\Internal\RunCallbackController;
use App\Models\ActivitySegment;
use App\Models\AnalysisRun;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission(Permission::ViewData);

        $filters = $request->validate([
            'run_id' => ['nullable', 'uuid'],
            'label' => ['nullable', Rule::in(RunCallbackController::ACTIVITY_LABELS)],
            'zone_type' => ['nullable', 'in:prep,stove,sink,cleaning,restricted'],
        ]);

        $runs = AnalysisRun::query()->where('status', 'SUCCEEDED')->with('video:id,original_name')
            ->orderByDesc('created_at')->limit(50)->get(['id', 'mode', 'video_asset_id', 'created_at']);

        $runId = $filters['run_id'] ?? ($runs->firstWhere('mode', 'actual') ?? $runs->first())?->id;

        $segments = ActivitySegment::query()
            ->when($runId, fn ($q) => $q->where('run_id', $runId), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($filters['label'] ?? null, fn ($q, $v) => $q->where('label', $v))
            ->when($filters['zone_type'] ?? null, fn ($q, $v) => $q->whereHas('zone', fn ($z) => $z->where('type', $v)))
            ->with(['track:id,track_key', 'zone:id,name,type'])
            ->orderBy('start_ms')
            ->paginate(50)
            ->withQueryString();

        $totals = ActivitySegment::query()
            ->when($runId, fn ($q) => $q->where('run_id', $runId), fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw('label, SUM(end_ms - start_ms) AS ms')
            ->groupBy('label')->orderBy('label')->get();

        return Inertia::render('activities/index', [
            'runs' => $runs,
            'selectedRunId' => $runId,
            'segments' => $segments,
            'totals' => $totals,
            'filters' => $filters,
            'labels' => RunCallbackController::ACTIVITY_LABELS,
        ]);
    }
}
