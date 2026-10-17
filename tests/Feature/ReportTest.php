<?php

use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\ReportScheduler;
use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\ActivitySegment;
use App\Models\PersonTrack;
use App\Models\PpeAssessment;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Notifications\ReportReady;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
    $video = makeReadyVideo(makeLocation($this->tenant)['source']);
    [$this->run] = runningRun($video, 'actual');
    $this->run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
    [$this->simRun] = runningRun($video, 'simulation');
    $this->simRun->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

    $track = PersonTrack::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'run_id' => $this->run->id, 'track_key' => '1', 'first_ms' => 0, 'last_ms' => 10000]);
    ActivitySegment::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'run_id' => $this->run->id, 'track_id' => $track->id, 'label' => 'in_stove_zone', 'start_ms' => 0, 'end_ms' => 10000]);
    foreach ([['present', 0, 6000], ['missing', 6000, 8000], ['unknown', 8000, 10000]] as [$state, $s, $e]) {
        PpeAssessment::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'run_id' => $this->run->id, 'track_id' => $track->id, 'item_code' => 'hair_cover', 'required' => true, 'state' => $state, 'start_ms' => $s, 'end_ms' => $e]);
    }

    $this->confirmed = makeEvent($this->run, ['review_status' => ReviewStatus::Confirmed]);
    makeEvent($this->run);
    makeEvent($this->simRun);
});

function requestReport(object $test, array $data = [])
{
    return $test->actingAs($test->owner)->post(route('reports.store'), $data + [
        'format' => 'csv', 'from_date' => now()->subDay()->toDateString(), 'to_date' => now()->addDay()->toDateString(),
    ]);
}

test('a CSV report excludes simulation by default and is immutable after generation', function () {
    Notification::fake();
    requestReport($this)->assertSessionHasNoErrors();

    $report = Report::query()->withoutGlobalScopes()->firstOrFail();
    expect($report->status)->toBe('READY')
        ->and($report->manifest['event_count'])->toBe(2)
        ->and($report->manifest['contains_simulation'])->toBeFalse()
        ->and($report->manifest['run_ids'])->toBe([$this->run->id]);

    $csv = Storage::disk('local')->get($report->key);
    expect($csv)->toStartWith("\u{FEFF}event_id,")
        ->and(substr_count(trim($csv), "\n"))->toBe(2)
        ->and(hash('sha256', $csv))->toBe($report->checksum);

    makeEvent($this->run);
    expect(Storage::disk('local')->get($report->key))->toBe($csv);

    Notification::assertSentTo($this->owner, ReportReady::class);
});

test('simulation can be included explicitly and is marked', function () {
    requestReport($this, ['include_simulation' => true]);

    $report = Report::query()->withoutGlobalScopes()->firstOrFail();
    expect($report->manifest['event_count'])->toBe(3)->and($report->manifest['contains_simulation'])->toBeTrue();
});

test('PPE compliance excludes unknown time and reports coverage separately', function () {
    $data = app(ReportBuilder::class)->build(Report::query()->withoutGlobalScopes()->create([
        'tenant_id' => $this->tenant->id, 'format' => 'csv', 'status' => 'QUEUED', 'cutoff_at' => now(),
        'filters' => ['from_date' => now()->subDay()->toDateString(), 'to_date' => now()->addDay()->toDateString()],
    ]));

    expect($data['ppe'][0])->toMatchArray([
        'item' => 'hair_cover', 'present_s' => 6.0, 'missing_s' => 2.0, 'unknown_s' => 2.0,
        'compliance_ratio' => 0.75, 'coverage' => 0.8,
    ])->and($data['summary'])->toMatchArray(['total_candidates' => 2, 'confirmed' => 1, 'unresolved' => 2])
        ->and($data['activity']['labels'][0])->toMatchArray(['label' => 'in_stove_zone', 'person_seconds' => 10.0]);
});

test('a PDF report is generated', function () {
    requestReport($this, ['format' => 'pdf'])->assertSessionHasNoErrors();

    $report = Report::query()->withoutGlobalScopes()->firstOrFail();
    expect($report->status)->toBe('READY')
        ->and(Storage::disk('local')->get($report->key))->toStartWith('%PDF');
});

test('downloads are authorised, tenant scoped and audited', function () {
    requestReport($this);
    $report = Report::query()->withoutGlobalScopes()->firstOrFail();
    $viewer = tenantUser(Role::Viewer, $this->tenant);
    $operator = tenantUser(Role::Operator, $this->tenant);

    $this->actingAs($viewer)->get(route('reports.download', $report))->assertOk();
    $this->actingAs($operator)->get(route('reports.download', $report))->assertForbidden();
    $this->actingAs(tenantUser())->get(route('reports.download', $report))->assertNotFound();

    $this->assertDatabaseHas('audit_logs', ['action' => 'report.downloaded', 'subject_id' => $report->id, 'actor_id' => $viewer->id]);
});

test('viewers cannot generate reports', function () {
    $this->actingAs(tenantUser(Role::Viewer, $this->tenant))->post(route('reports.store'), [
        'format' => 'csv', 'from_date' => '2026-09-01', 'to_date' => '2026-09-30',
    ])->assertForbidden();
});

test('schedules create exactly one report per period even when ticked twice', function () {
    $this->actingAs($this->owner)->post(route('report-schedules.store'), ['frequency' => 'daily', 'format' => 'csv'])
        ->assertSessionHasNoErrors();
    $schedule = ReportSchedule::query()->withoutGlobalScopes()->firstOrFail();

    $scheduler = app(ReportScheduler::class);
    $dueAt = CarbonImmutable::parse($schedule->next_run_at);
    $tick = $dueAt->addMinute();

    expect($scheduler->runDue($tick))->toBe(1);

    // A second scheduler process that read the old next_run_at for the same period.
    $schedule->refresh()->update(['next_run_at' => $dueAt]);
    expect($scheduler->runDue($tick))->toBe(0)
        ->and(Report::query()->withoutGlobalScopes()->count())->toBe(1);
});
