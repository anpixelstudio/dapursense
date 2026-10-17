<?php

namespace App\Http\Controllers;

use App\Domain\Analysis\AnalysisService;
use App\Enums\Permission;
use App\Models\AiModel;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\PersonTrack;
use App\Models\RuleSet;
use App\Models\VideoAsset;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnalysisController extends Controller
{
    public const OBSERVATION_CHUNK_MS = 10000;

    public function store(Request $request, VideoAsset $video, AnalysisService $service): RedirectResponse
    {
        $this->authorizePermission(Permission::RunAnalysis, $video);
        $tenantId = $this->tenantId();

        $validated = $request->validate([
            'model_id' => ['required', 'uuid'],
            'rule_set_id' => ['required', 'uuid', Rule::exists('rule_sets', 'id')->where('tenant_id', $tenantId)],
            'mode' => ['required', 'in:actual,simulation'],
        ]);

        $model = AiModel::query()->availableTo($tenantId)->find($validated['model_id']);
        abort_if($model === null, 404, 'Model tidak tersedia untuk organisasi ini.');

        $run = $service->start($video, $model, RuleSet::query()->with('activeRevision')->findOrFail($validated['rule_set_id']), $validated['mode'], $request->user());
        Audit::log('analysis.requested', $run, ['mode' => $run->mode, 'model' => $model->name.' '.$model->version]);
        $this->toast('Analisis masuk antrean.');

        return redirect()->route('analyses.show', $run);
    }

    public function show(AnalysisRun $run): Response
    {
        $this->authorizePermission(Permission::ViewData, $run);
        $run->load(['video', 'model:id,name,version,runtime,capabilities', 'ruleRevision:id,version,rule_set_id', 'ruleRevision.ruleSet:id,name', 'requester:id,name', 'source:id,name']);

        return Inertia::render('analyses/show', [
            'run' => $run,
            'tracks' => PersonTrack::query()->where('run_id', $run->id)
                ->with([
                    'activitySegments' => fn ($q) => $q->orderBy('start_ms'),
                    'ppeAssessments' => fn ($q) => $q->orderBy('start_ms'),
                ])
                ->orderBy('first_ms')
                ->get(),
            'events' => AnomalyEvent::query()->where('run_id', $run->id)->orderBy('first_ms')
                ->get(['id', 'type', 'category', 'severity', 'review_status', 'lifecycle', 'first_ms', 'last_ms', 'track_id', 'peak_score', 'is_simulation']),
            'observationsExpired' => $this->observationsExpired($run),
            'observationChunkMs' => self::OBSERVATION_CHUNK_MS,
        ]);
    }

    public function status(AnalysisRun $run): JsonResponse
    {
        $this->authorizePermission(Permission::ViewData, $run);

        return response()->json($run->only([
            'id', 'status', 'phase', 'progress', 'processed_ms', 'attempt', 'error_code', 'error_message',
            'heartbeat_at', 'started_at', 'finished_at', 'cancel_requested_at',
        ]) + [
            'event_count' => AnomalyEvent::query()->where('run_id', $run->id)->count(),
            'queue_position' => $run->status->value === 'QUEUED'
                ? AnalysisRun::query()->withoutGlobalScopes()->where('status', 'QUEUED')->where('created_at', '<', $run->created_at)->count() + 1
                : null,
        ]);
    }

    public function cancel(AnalysisRun $run, AnalysisService $service): RedirectResponse
    {
        $this->authorizePermission(Permission::RunAnalysis, $run);

        if (! $run->status->isActive()) {
            return back()->withErrors(['run' => 'Run sudah selesai dan tidak dapat dibatalkan.']);
        }

        $service->cancel($run);
        Audit::log('analysis.cancel_requested', $run);
        $this->toast('Pembatalan dikirim ke worker.');

        return back();
    }

    /**
     * Returns only frames near the playhead; full observations are stored as
     * time-indexed JSONL chunks on the private disk, never per-frame rows.
     */
    public function observations(Request $request, AnalysisRun $run): JsonResponse
    {
        $this->authorizePermission(Permission::ViewData, $run);
        $validated = $request->validate([
            'from_ms' => ['required', 'integer', 'min:0'],
            'to_ms' => ['required', 'integer', 'gte:from_ms'],
        ]);

        $from = (int) $validated['from_ms'];
        $to = min((int) $validated['to_ms'], $from + 30000);

        if ($this->observationsExpired($run)) {
            return response()->json(['expired' => true, 'frames' => []]);
        }

        $disk = Storage::disk(config('kitchen.storage_disk'));
        $frames = [];

        for ($chunk = intdiv($from, self::OBSERVATION_CHUNK_MS); $chunk <= intdiv($to, self::OBSERVATION_CHUNK_MS); $chunk++) {
            $key = sprintf('%s/observations/%06d.jsonl', $run->directory(), $chunk);

            if (! $disk->exists($key)) {
                continue;
            }

            foreach (explode("\n", trim($disk->get($key))) as $line) {
                $frame = json_decode($line, true);

                if (is_array($frame) && $frame['source_timestamp_ms'] >= $from && $frame['source_timestamp_ms'] <= $to) {
                    $frames[] = $frame;
                }
            }

            if (count($frames) > 2000) {
                break;
            }
        }

        return response()->json(['expired' => false, 'frames' => array_slice($frames, 0, 2000)]);
    }

    private function observationsExpired(AnalysisRun $run): bool
    {
        return (bool) data_get($run->result_manifest, 'observations_expired', false);
    }
}
