<?php

namespace App\Domain\Reports;

use App\Models\ActivitySegment;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\PpeAssessment;
use App\Models\Report;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * All aggregates are recomputable from stored intervals. Candidate counts and
 * confirmed counts are reported separately; no "accuracy" score is produced.
 */
class ReportBuilder
{
    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        $tenant = Tenant::query()->findOrFail($report->tenant_id);
        $filters = $report->filters;
        $tz = $tenant->timezone;
        $from = CarbonImmutable::parse($filters['from_date'], $tz)->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['to_date'], $tz)->endOfDay()->utc();
        $cutoff = $report->cutoff_at;

        $runs = $this->runs($report->tenant_id, $filters, $from, $to, $cutoff);
        $runIds = $runs->pluck('id');

        $events = $this->events($report->tenant_id, $filters, $from, $to, $cutoff, $runIds);
        $violations = $events->where('category.value', 'violation');

        return [
            'identity' => [
                'report_id' => $report->id,
                'organization' => $tenant->name,
                'period' => ['from' => $filters['from_date'], 'to' => $filters['to_date']],
                'timezone' => $tz,
                'generated_at' => now()->toIso8601String(),
                'cutoff_at' => $cutoff->toIso8601String(),
                'contains_simulation' => $runs->contains('mode', 'simulation'),
            ],
            'filters' => $filters,
            'runs' => $runs->map(fn (AnalysisRun $r) => [
                'id' => $r->id,
                'mode' => $r->mode,
                'source' => $r->source?->name,
                'video' => $r->video?->original_name,
                'model' => $r->model ? "{$r->model->name} {$r->model->version}" : null,
                'rule_revision' => $r->ruleRevision?->version,
                'app_version' => $r->app_version,
                'status' => $r->status->value,
                'run_date' => $r->created_at->setTimezone($tz)->toDateString(),
            ])->values()->all(),
            'summary' => [
                'total_candidates' => $violations->count(),
                'confirmed' => $violations->where('review_status.value', 'CONFIRMED')->count(),
                'resolved' => $violations->where('review_status.value', 'RESOLVED')->count(),
                'dismissed' => $violations->where('review_status.value', 'DISMISSED')->count(),
                'unresolved' => $violations->whereIn('review_status.value', ['OPEN', 'ACKNOWLEDGED', 'CONFIRMED'])->count(),
                'review_needed' => $events->where('category.value', 'review_needed')->count(),
                'system' => $events->where('category.value', 'system')->count(),
            ],
            'activity' => $this->activity($runIds),
            'ppe' => $this->ppe($runIds),
            'events' => $events->map(fn (AnomalyEvent $e) => $this->eventRow($e, $tz))->values()->all(),
            'operational' => [
                'failed_runs' => $runs->where('status.value', 'FAILED')->count(),
                'source_unreadable' => $events->where('type', 'source_unreadable')->count(),
                'analysis_stalled' => $events->where('type', 'analysis_stalled')->count(),
            ],
        ];
    }

    /** @return Collection<int, AnalysisRun> */
    private function runs(string $tenantId, array $filters, CarbonImmutable $from, CarbonImmutable $to, $cutoff): Collection
    {
        return AnalysisRun::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '<=', $cutoff)
            ->whereBetween('created_at', [$from, $to])
            ->when(! ($filters['include_simulation'] ?? false), fn ($q) => $q->where('mode', 'actual'))
            ->when($filters['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when($filters['kitchen_id'] ?? null, fn ($q, $v) => $q->whereHas('source', fn ($s) => $s->withoutGlobalScopes()->where('kitchen_id', $v)))
            ->when($filters['property_id'] ?? null, fn ($q, $v) => $q->whereHas('source.kitchen', fn ($k) => $k->withoutGlobalScopes()->where('property_id', $v)))
            ->with([
                'source' => fn ($q) => $q->withoutGlobalScopes(),
                'video' => fn ($q) => $q->withoutGlobalScopes(),
                'ruleRevision' => fn ($q) => $q->withoutGlobalScopes(),
                'model',
            ])
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, AnomalyEvent> */
    private function events(string $tenantId, array $filters, CarbonImmutable $from, CarbonImmutable $to, $cutoff, Collection $runIds): Collection
    {
        return AnomalyEvent::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '<=', $cutoff)
            ->where(function (Builder $q) use ($runIds, $from, $to) {
                // Events from video without recorded_at are grouped by run date, not by an invented occurrence time.
                $q->whereIn('run_id', $runIds)
                    ->orWhere(fn ($s) => $s->whereNull('run_id')->whereBetween('created_at', [$from, $to]));
            })
            ->when(! ($filters['include_simulation'] ?? false), fn ($q) => $q->where('is_simulation', false))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['review_status'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->when($filters['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->with([
                'source' => fn ($q) => $q->withoutGlobalScopes(),
                'track' => fn ($q) => $q->withoutGlobalScopes(),
                'media' => fn ($q) => $q->withoutGlobalScopes(),
                'lastReviewer',
                'reviews' => fn ($q) => $q->withoutGlobalScopes()->with('actor'),
            ])
            ->orderBy('created_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function activity(Collection $runIds): array
    {
        $rows = ActivitySegment::query()->withoutGlobalScopes()
            ->whereIn('run_id', $runIds)
            ->selectRaw('label, SUM(end_ms - start_ms) AS total_ms, COUNT(DISTINCT track_id) AS tracks')
            ->groupBy('label')
            ->orderBy('label')
            ->get();

        return [
            'unit' => 'person-seconds',
            'labels' => $rows->map(fn ($r) => [
                'label' => $r->label,
                'person_seconds' => round($r->total_ms / 1000, 1),
                'tracks' => (int) $r->tracks,
            ])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ppe(Collection $runIds): array
    {
        $rows = PpeAssessment::query()->withoutGlobalScopes()
            ->whereIn('run_id', $runIds)
            ->where('required', true)
            ->selectRaw('item_code, state, SUM(end_ms - start_ms) AS total_ms')
            ->groupBy('item_code', 'state')
            ->get()
            ->groupBy('item_code');

        return $rows->map(function (Collection $states, string $item) {
            $ms = fn (string $s) => (int) ($states->firstWhere('state', $s)?->total_ms ?? 0);
            [$present, $missing, $unknown] = [$ms('present'), $ms('missing'), $ms('unknown')];
            $assessable = $present + $missing;
            $relevant = $assessable + $unknown;

            return [
                'item' => $item,
                'present_s' => round($present / 1000, 1),
                'missing_s' => round($missing / 1000, 1),
                'unknown_s' => round($unknown / 1000, 1),
                'compliance_ratio' => $assessable > 0 ? round($present / $assessable, 4) : null,
                'coverage' => $relevant > 0 ? round($assessable / $relevant, 4) : null,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function eventRow(AnomalyEvent $e, string $tz): array
    {
        $lastReview = $e->reviews->last();
        $media = $e->media->keyBy('kind');

        return [
            'event_id' => $e->id,
            'run_id' => $e->run_id,
            'simulation' => $e->is_simulation,
            'source' => $e->source?->name,
            'type' => $e->type,
            'category' => $e->category->value,
            'severity' => $e->severity,
            'review_status' => $e->review_status->value,
            'track' => $e->track ? 'Person '.$e->track->track_key : null,
            'relative_start' => $e->first_ms !== null ? $this->clock($e->first_ms) : null,
            'relative_end' => $e->last_ms !== null ? $this->clock($e->last_ms) : null,
            'occurred_at' => $e->occurred_at?->setTimezone($tz)->toDateTimeString(),
            'duration_s' => $e->first_ms !== null && $e->last_ms !== null ? round(($e->last_ms - $e->first_ms) / 1000, 1) : null,
            'reviewer' => $lastReview?->actor?->name,
            'review_reason' => $lastReview ? trim(($lastReview->reason_code ? "[{$lastReview->reason_code}] " : '').($lastReview->reason ?? '')) : null,
            'action_note' => $lastReview?->action_note,
            'reviewed_at' => $lastReview?->created_at?->setTimezone($tz)->toDateTimeString(),
            'snapshot' => $media->get('snapshot')?->state ?? 'NONE',
            'clip' => $media->get('clip')?->state ?? 'NONE',
            'event_url' => route('events.show', $e->id),
        ];
    }

    private function clock(int $ms): string
    {
        $s = intdiv($ms, 1000);

        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
}
