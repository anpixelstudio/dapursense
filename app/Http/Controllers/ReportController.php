<?php

namespace App\Http\Controllers;

use App\Domain\Reports\GenerateReportJob;
use App\Domain\Reports\ReportScheduler;
use App\Enums\Permission;
use App\Enums\ReviewStatus;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Models\Property;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(): Response
    {
        $this->authorizePermission(Permission::ViewData);

        return Inertia::render('reports/index', [
            'reports' => Report::query()->with('requester:id,name')->orderByDesc('created_at')->limit(50)->get(),
            'schedules' => ReportSchedule::query()->orderBy('created_at')->get(),
            'options' => [
                'properties' => Property::query()->orderBy('name')->get(['id', 'name']),
                'kitchens' => Kitchen::query()->orderBy('name')->get(['id', 'name', 'property_id']),
                'sources' => MediaSource::query()->orderBy('name')->get(['id', 'name']),
                'review_statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], ReviewStatus::cases()),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission(Permission::GenerateReports);

        $validated = $request->validate(['format' => ['required', 'in:csv,pdf'], ...$this->filterRules()]);
        $format = $validated['format'];
        unset($validated['format']);
        $validated['include_simulation'] = (bool) ($validated['include_simulation'] ?? false);

        $report = Report::query()->create([
            'requested_by' => $request->user()->id,
            'format' => $format,
            'status' => 'QUEUED',
            'filters' => $validated,
            'cutoff_at' => now(),
        ]);

        GenerateReportJob::dispatch($report->id);
        Audit::log('report.requested', $report, ['format' => $format, 'filters' => $validated]);
        $this->toast('Report queued for generation.');

        return back();
    }

    public function download(Report $report): BinaryFileResponse
    {
        $this->authorizePermission(Permission::DownloadReports, $report);
        abort_unless($report->status === 'READY' && $report->key, 404, 'Report is not ready or has expired.');

        $disk = Storage::disk($report->disk);
        abort_unless($disk->exists($report->key), 404, 'Report file was deleted per retention policy.');

        Audit::log('report.downloaded', $report);

        return response()->download($disk->path($report->key), "report-{$report->id}.{$report->format}", [
            'Content-Type' => $report->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
        ]);
    }

    public function storeSchedule(Request $request, ReportScheduler $scheduler): RedirectResponse
    {
        $this->authorizePermission(Permission::GenerateReports);

        $validated = $request->validate([
            'frequency' => ['required', 'in:daily,weekly'],
            'format' => ['required', 'in:csv,pdf'],
            'include_simulation' => ['nullable', 'boolean'],
            'source_id' => $this->filterRules()['source_id'],
        ]);

        $tz = app(TenantContext::class)->tenant()->timezone;

        $schedule = ReportSchedule::query()->create([
            'created_by' => $request->user()->id,
            'frequency' => $validated['frequency'],
            'format' => $validated['format'],
            'timezone' => $tz,
            'filters' => array_filter([
                'include_simulation' => (bool) ($validated['include_simulation'] ?? false),
                'source_id' => $validated['source_id'] ?? null,
            ], fn ($v) => $v !== null),
            'recipients' => [],
            'next_run_at' => $scheduler->nextRunAt($validated['frequency'], $tz),
        ]);

        Audit::log('report.schedule_created', $schedule);
        $this->toast('Report schedule created.');

        return back();
    }

    public function destroySchedule(ReportSchedule $schedule): RedirectResponse
    {
        $this->authorizePermission(Permission::GenerateReports, $schedule);
        $schedule->delete();
        Audit::log('report.schedule_deleted', $schedule);
        $this->toast('Report schedule removed.');

        return back();
    }

    /** @return array<string, mixed> */
    private function filterRules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'property_id' => ['nullable', 'uuid', Rule::exists('properties', 'id')->where('tenant_id', $tenantId)],
            'kitchen_id' => ['nullable', 'uuid', Rule::exists('kitchens', 'id')->where('tenant_id', $tenantId)],
            'source_id' => ['nullable', 'uuid', Rule::exists('media_sources', 'id')->where('tenant_id', $tenantId)],
            'type' => ['nullable', 'string', 'max:64'],
            'severity' => ['nullable', 'string', 'max:16'],
            'review_status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'include_simulation' => ['nullable', 'boolean'],
        ];
    }
}
