<?php

namespace App\Http\Controllers;

use App\Domain\Events\ReviewConflict;
use App\Domain\Events\ReviewService;
use App\Enums\EventCategory;
use App\Enums\Permission;
use App\Enums\ReviewStatus;
use App\Models\AnomalyEvent;
use App\Models\EventMedia;
use App\Models\MediaSource;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission(Permission::ViewData);

        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', Rule::enum(EventCategory::class)],
            'severity' => ['nullable', 'string', 'max:16'],
            'review_status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'source_id' => ['nullable', 'uuid'],
            'run_id' => ['nullable', 'uuid'],
            'model' => ['nullable', 'string', 'max:64'],
            'simulation' => ['nullable', 'in:only,exclude,all'],
        ]);

        $events = AnomalyEvent::query()
            ->with([
                'source:id,name', 'track:id,track_key', 'run:id,mode,model_id,video_asset_id', 'run.model:id,name,version', 'run.video:id,original_name',
                'media' => fn ($q) => $q->where('kind', 'snapshot')->where('state', 'READY')->select('id', 'event_id', 'kind'),
            ])
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['review_status'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->when($filters['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when($filters['run_id'] ?? null, fn ($q, $v) => $q->where('run_id', $v))
            ->when($filters['model'] ?? null, fn ($q, $v) => $q->whereHas('run.model', fn ($m) => $m->where('name', $v)))
            ->when(($filters['simulation'] ?? 'all') === 'only', fn ($q) => $q->where('is_simulation', true))
            ->when(($filters['simulation'] ?? 'all') === 'exclude', fn ($q) => $q->where('is_simulation', false))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('events/index', [
            'events' => $events,
            'filters' => $filters,
            'sources' => MediaSource::query()->orderBy('name')->get(['id', 'name']),
            'types' => AnomalyEvent::query()->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    public function show(AnomalyEvent $event): Response
    {
        $this->authorizePermission(Permission::ViewData, $event);

        $event->load([
            'source:id,name', 'track', 'media', 'lastReviewer:id,name',
            'reviews' => fn ($q) => $q->with('actor:id,name'),
            'run:id,mode,status,model_id,rule_revision_id,video_asset_id,app_version,input_snapshot',
            'run.model:id,name,version,capabilities', 'run.ruleRevision:id,version,rule_set_id', 'run.ruleRevision.ruleSet:id,name', 'run.video:id,original_name,recorded_at,recorded_timezone,duration_ms,width,height,preview_key',
        ]);

        $ppe = $event->track
            ? $event->track->ppeAssessments()->orderBy('start_ms')->get()
            : collect();

        return Inertia::render('events/show', [
            'event' => $event,
            'ppe' => $ppe,
            'zones' => data_get($event->run?->input_snapshot, 'zones', []),
            'dismissReasons' => ReviewStatus::DISMISS_REASONS,
            'allowedTransitions' => collect(ReviewStatus::cases())
                ->filter(fn (ReviewStatus $to) => $event->review_status->canTransitionTo($to, $event->category))
                ->map(fn (ReviewStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
        ]);
    }

    public function review(Request $request, AnomalyEvent $event, ReviewService $service): RedirectResponse|JsonResponse
    {
        $this->authorizePermission(
            in_array($request->input('to_status'), ['OPEN'], true) ? Permission::ReopenEvents : Permission::ReviewEvents,
            $event,
        );

        $validated = $request->validate([
            'to_status' => ['required', Rule::enum(ReviewStatus::class)],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason_code' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'action_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $service->transition($event, $validated, $request->user(), app(TenantContext::class)->role());
        } catch (ReviewConflict $conflict) {
            $payload = [
                'error' => 'revision_conflict',
                'message' => 'Another reviewer already changed this event. Check the latest decision before continuing.',
                'current' => [
                    'review_status' => $conflict->current->review_status->value,
                    'revision' => $conflict->current->revision,
                    'last_reviewed_by' => $conflict->current->lastReviewer?->name,
                ],
            ];

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json($payload, 409);
            }

            return back()->withErrors(['expected_revision' => $payload['message']]);
        }

        $this->toast('Review decision saved.');

        return back();
    }

    public function hold(Request $request, AnomalyEvent $event): RedirectResponse
    {
        $this->authorizePermission(Permission::HoldEvidence, $event);
        $validated = $request->validate(['hold' => ['required', 'boolean']]);

        $event->update(['evidence_hold' => $validated['hold']]);
        Audit::log($validated['hold'] ? 'event.hold_set' : 'event.hold_released', $event);
        $this->toast($validated['hold'] ? 'Evidence held from automatic deletion.' : 'Evidence hold released.');

        return back();
    }

    public function media(AnomalyEvent $event, EventMedia $media): BinaryFileResponse
    {
        $this->authorizePermission(Permission::ViewData, $event);
        abort_unless($media->event_id === $event->id, 404);
        abort_unless($media->state === 'READY' && $media->key, 404, 'Evidence unavailable: '.($media->error_code ?? strtolower($media->state)));

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->key), 404, 'Evidence file no longer exists.');

        $mime = match ($media->kind) {
            'snapshot' => 'image/jpeg',
            'clip' => 'video/mp4',
            default => 'application/json',
        };

        return response()->file($disk->path($media->key), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
