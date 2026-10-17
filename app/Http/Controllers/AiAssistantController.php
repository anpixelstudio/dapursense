<?php

namespace App\Http\Controllers;

use App\Domain\Ai\AiAssistant;
use App\Enums\Permission;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\MediaSource;
use App\Models\PersonTrack;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\RuleSet;
use App\Models\TenantMembership;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class AiAssistantController extends Controller
{
    public function index(AiAssistant $ai): Response
    {
        $this->authorizePermission(Permission::ViewData);

        return Inertia::render('assistant/index', [
            'provider' => $ai->provider(),
            'model' => config('kitchen.genai.model'),
            'ready' => $ai->provider() === 'stub' || filled(config('kitchen.genai.api_key')),
        ]);
    }

    public function summarizeEvent(Request $request, AnomalyEvent $event, AiAssistant $ai): JsonResponse
    {
        $this->authorizePermission(Permission::ViewData, $event);

        $event->loadMissing(['source:id,name', 'run:id,mode,video_asset_id', 'run.video:id,original_name', 'track:id,track_key']);

        $locale = $this->localeFrom($request);

        try {
            $text = $ai->summarizeEvent([
                'id' => $event->id,
                'type' => $event->type,
                'type_label' => $this->eventTypeLabel($event->type),
                'category' => $event->category?->value ?? $event->category,
                'review_status' => $event->review_status?->value ?? $event->review_status,
                'source' => $event->source?->name,
                'device_name' => $event->evidence['device_name'] ?? null,
                'video' => $event->run?->video?->original_name,
                'track' => $event->track?->track_key,
                'first_ms' => $event->first_ms,
                'last_ms' => $event->last_ms,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'created_at' => $event->created_at?->toIso8601String(),
                'evidence' => $event->evidence,
                'is_simulation' => $event->is_simulation,
            ], $locale);
        } catch (Throwable $e) {
            return $this->aiError($e);
        }

        return response()->json([
            'provider' => $ai->provider(),
            'locale' => $locale,
            'text' => $text,
        ]);
    }

    public function chat(Request $request, AiAssistant $ai): JsonResponse
    {
        $this->authorizePermission(Permission::ViewData);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'locale' => ['nullable', 'in:en,id'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ]);

        $locale = $validated['locale'] ?? 'en';
        $context = $this->assistantChatContext();

        try {
            $text = $ai->chatSop(
                $validated['message'],
                $validated['history'] ?? [],
                $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                $locale,
            );
        } catch (Throwable $e) {
            return $this->aiError($e);
        }

        return response()->json([
            'provider' => $ai->provider(),
            'locale' => $locale,
            'text' => $text,
        ]);
    }

    public function dailyReport(Request $request, AiAssistant $ai): JsonResponse
    {
        $this->authorizePermission(Permission::ViewData);

        // Locked surface: only allow focus presets — no free-form prompt field.
        $validated = $request->validate([
            'focus' => ['nullable', 'in:operations,safety,throughput'],
            'locale' => ['nullable', 'in:en,id'],
        ]);

        $focus = $validated['focus'] ?? 'operations';
        $locale = $validated['locale'] ?? 'en';
        $since = now()->subDays(7);

        $runIds = AnalysisRun::query()
            ->where('status', 'SUCCEEDED')
            ->where('finished_at', '>=', $since)
            ->pluck('id');

        $stats = [
            'period' => ['from' => $since->toIso8601String(), 'to' => now()->toIso8601String()],
            'open_events' => AnomalyEvent::query()->whereIn('review_status', ['OPEN', 'ACKNOWLEDGED'])->count(),
            'events_7d' => AnomalyEvent::query()->where('created_at', '>=', $since)->count(),
            'restricted' => AnomalyEvent::query()->where('type', 'restricted_zone')->where('created_at', '>=', $since)->count(),
            'people' => PersonTrack::query()->whereIn('run_id', $runIds)->count(),
            'analysed_videos' => AnalysisRun::query()
                ->where('status', 'SUCCEEDED')
                ->where('finished_at', '>=', $since)
                ->whereNotNull('video_asset_id')
                ->distinct()
                ->count('video_asset_id'),
            'active_sources' => MediaSource::query()->where('status', 'active')->count(),
            'by_type' => AnomalyEvent::query()->where('created_at', '>=', $since)
                ->selectRaw('type, COUNT(*) AS total')
                ->groupBy('type')
                ->pluck('total', 'type'),
        ];

        try {
            $text = $ai->dailyReport($stats, $focus, $locale);
        } catch (Throwable $e) {
            return $this->aiError($e);
        }

        return response()->json([
            'provider' => $ai->provider(),
            'focus' => $focus,
            'locale' => $locale,
            'stats' => $stats,
            'text' => $text,
        ]);
    }

    private function localeFrom(Request $request): string
    {
        $locale = strtolower((string) $request->input('locale', 'en'));

        return in_array($locale, ['en', 'id'], true) ? $locale : 'en';
    }

    /**
     * Live helper context for chat: SOP, period reports, team, people detected, ops.
     *
     * @return array<string, mixed>
     */
    private function assistantChatContext(): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $yesterdayStart = now()->subDay()->startOfDay();
        $yesterdayEnd = now()->subDay()->endOfDay();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $periods = [
            'today' => [$todayStart, $todayEnd],
            'yesterday' => [$yesterdayStart, $yesterdayEnd],
            'this_month' => [$monthStart, $monthEnd],
        ];

        $sop = RuleSet::query()->with('activeRevision:id,rule_set_id,version,config')
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->map(fn (RuleSet $set) => [
                'name' => $set->name,
                'version' => $set->activeRevision?->version,
                'config' => $set->activeRevision?->config,
            ])
            ->all();

        $reportsByPeriod = [];
        foreach ($periods as $key => [$from, $to]) {
            $items = $this->reportsInRange($from, $to);
            $reportsByPeriod[$key] = [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'count' => count($items),
                'items' => $items,
            ];
        }

        $opsByPeriod = [];
        foreach ($periods as $key => [$from, $to]) {
            $opsByPeriod[$key] = $this->opsSnapshotInRange($from, $to);
        }

        $peopleByPeriod = [];
        foreach ($periods as $key => [$from, $to]) {
            $peopleByPeriod[$key] = $this->peopleDetectedInRange($from, $to);
        }

        $tenantId = app(TenantContext::class)->id();
        $members = TenantMembership::query()
            ->with('user:id,name,email')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->orderBy('role')
            ->limit(50)
            ->get()
            ->map(fn (TenantMembership $m) => [
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role?->value ?? $m->role,
                'status' => $m->status,
            ])
            ->all();

        $schedules = ReportSchedule::query()
            ->where('enabled', true)
            ->orderBy('next_run_at')
            ->limit(10)
            ->get()
            ->map(fn (ReportSchedule $schedule) => [
                'frequency' => $schedule->frequency,
                'format' => $schedule->format,
                'timezone' => $schedule->timezone,
                'next_run_at' => $schedule->next_run_at?->toIso8601String(),
                'due_today' => $schedule->next_run_at
                    ? $schedule->next_run_at->between($todayStart, $todayEnd)
                    : false,
            ])
            ->all();

        return [
            'as_of' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'sop_rules' => $sop,
            // Backward-compatible aliases for older prompt/stub paths
            'reports_today' => $reportsByPeriod['today']['items'],
            'reports_today_count' => $reportsByPeriod['today']['count'],
            'reports_by_period' => $reportsByPeriod,
            'report_schedules' => $schedules,
            'ops_snapshot_today' => $opsByPeriod['today'],
            'ops_by_period' => $opsByPeriod,
            'people_detected' => $peopleByPeriod,
            'team' => [
                'active_member_count' => count($members),
                'members' => $members,
                'note' => 'Registered DapurSense users for this organization (not HR payroll headcount).',
            ],
            'ui_hints' => [
                'reports_page' => '/reports',
                'members_page' => '/members',
                'assistant_report_tab' => 'Assistant → Report tab (AI narrative from live stats)',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportsInRange(CarbonInterface $from, CarbonInterface $to): array
    {
        return Report::query()
            ->with('requester:id,name')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Report $report) => [
                'id' => $report->id,
                'format' => $report->format,
                'status' => $report->status,
                'created_at' => $report->created_at?->toIso8601String(),
                'completed_at' => $report->completed_at?->toIso8601String(),
                'requester' => $report->requester?->name,
                'error_code' => $report->error_code,
                'filters' => $report->filters,
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function opsSnapshotInRange(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'events_created' => AnomalyEvent::query()
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'restricted' => AnomalyEvent::query()
                ->where('type', 'restricted_zone')
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'open_or_acked_events' => AnomalyEvent::query()
                ->whereIn('review_status', ['OPEN', 'ACKNOWLEDGED'])
                ->count(),
            'analysed_videos' => AnalysisRun::query()
                ->where('status', 'SUCCEEDED')
                ->whereBetween('finished_at', [$from, $to])
                ->whereNotNull('video_asset_id')
                ->distinct()
                ->count('video_asset_id'),
            'active_sources' => MediaSource::query()->where('status', 'active')->count(),
        ];
    }

    /**
     * @return array{people_tracks: int, succeeded_runs: int}
     */
    private function peopleDetectedInRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $runIds = AnalysisRun::query()
            ->where('status', 'SUCCEEDED')
            ->whereBetween('finished_at', [$from, $to])
            ->pluck('id');

        return [
            'people_tracks' => PersonTrack::query()->whereIn('run_id', $runIds)->count(),
            'succeeded_runs' => $runIds->count(),
        ];
    }

    private function eventTypeLabel(string $type): string
    {
        return match ($type) {
            'ppe_missing' => 'PPE not detected',
            'restricted_zone' => 'Restricted zone entry',
            'unassessable_ppe' => 'PPE could not be assessed',
            'source_unreadable' => 'Video unreadable',
            'observation_lost' => 'Observation lost',
            'analysis_stalled' => 'Analysis stalled',
            'device_offline' => 'Device offline',
            default => $type,
        };
    }

    private function aiError(Throwable $e): JsonResponse
    {
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Gen AI service failed to respond.';

        return response()->json([
            'message' => $message,
            'provider' => config('kitchen.genai.provider'),
        ], 502);
    }
}
