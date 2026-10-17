<?php

use App\Enums\RunStatus;
use App\Models\ActivitySegment;
use App\Models\AnomalyEvent;
use App\Models\PersonTrack;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['ai-worker.test/*' => Http::response(['accepted' => true], 202)]);
    $this->tenant = tenantOf(tenantUser());
    $this->video = makeReadyVideo(makeLocation($this->tenant)['source']);
});

test('a stalled run gets a system event and is retried with a fresh attempt', function () {
    [$run] = runningRun($this->video);
    $run->update(['heartbeat_at' => now()->subMinutes(5), 'last_batch_sequence' => 4, 'processed_ms' => 8000]);
    $track = PersonTrack::query()->withoutGlobalScopes()->create(['tenant_id' => $run->tenant_id, 'run_id' => $run->id, 'track_key' => '1', 'first_ms' => 0, 'last_ms' => 1000]);
    ActivitySegment::query()->withoutGlobalScopes()->create(['tenant_id' => $run->tenant_id, 'run_id' => $run->id, 'track_id' => $track->id, 'label' => 'unknown', 'start_ms' => 0, 'end_ms' => 1000]);

    $this->artisan('analysis:watchdog')->assertSuccessful();

    $run->refresh();
    expect($run->attempt)->toBe(2)
        ->and($run->last_batch_sequence)->toBe(0)
        ->and($run->status)->toBe(RunStatus::Running)
        ->and(ActivitySegment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(AnomalyEvent::query()->withoutGlobalScopes()->where('type', 'analysis_stalled')->count())->toBe(1);

    Http::assertSent(fn ($r) => $r->url() === 'http://ai-worker.test/runs' && $r['attempt'] === 2);
});

test('a run that keeps stalling fails after the attempt limit', function () {
    [$run] = runningRun($this->video);
    $run->update(['heartbeat_at' => now()->subMinutes(5), 'attempt' => 3]);

    $this->artisan('analysis:watchdog')->assertSuccessful();

    expect($run->fresh())->status->toBe(RunStatus::Failed)->error_code->toBe('analysis_stalled');
});

test('a stalled run with a cancel request becomes cancelled', function () {
    [$run] = runningRun($this->video);
    $run->update(['heartbeat_at' => now()->subMinutes(5), 'cancel_requested_at' => now()]);

    $this->artisan('analysis:watchdog')->assertSuccessful();

    expect($run->fresh()->status)->toBe(RunStatus::Cancelled);
});

test('healthy runs are left alone', function () {
    [$run] = runningRun($this->video);

    $this->artisan('analysis:watchdog')->assertSuccessful();

    expect($run->fresh())->status->toBe(RunStatus::Running)->attempt->toBe(1);
});
