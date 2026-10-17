<?php

use App\Enums\RunStatus;
use App\Models\EventMedia;
use App\Models\Report;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
    $this->video = makeReadyVideo(makeLocation($this->tenant)['source']);
    Storage::disk('local')->put($this->video->original_key, 'orig');
    Storage::disk('local')->put($this->video->preview_key, 'prev');
});

test('expired originals are deleted and reanalysis becomes unavailable', function () {
    $this->video->update(['original_expires_at' => now()->subDay()]);

    $this->artisan('retention:cleanup')->assertSuccessful();

    $video = $this->video->fresh();
    expect($video->original_key)->toBeNull()->and($video->canReanalyze())->toBeFalse();
    Storage::disk('local')->assertMissing('tenants/'.$this->tenant->id.'/videos/'.$video->id.'/original.mp4');
});

test('active runs block deleting the original', function () {
    runningRun($this->video);
    $this->video->update(['original_expires_at' => now()->subDay()]);

    $this->artisan('retention:cleanup')->assertSuccessful();

    expect($this->video->fresh()->original_key)->not->toBeNull();
});

test('evidence hold blocks deleting media and originals', function () {
    [$run] = runningRun($this->video);
    $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()->subDays(40)]);
    $this->video->update(['original_expires_at' => now()->subDay()]);
    $event = makeEvent($run, ['evidence_hold' => true]);
    $key = $run->directory().'/evidence/snap.jpg';
    Storage::disk('local')->put($key, 'jpg');
    $media = EventMedia::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'event_id' => $event->id, 'kind' => 'snapshot', 'disk' => 'local', 'key' => $key, 'state' => 'READY', 'expires_at' => now()->subDay()]);

    $this->artisan('retention:cleanup')->assertSuccessful();

    expect($media->fresh()->state)->toBe('READY')
        ->and($this->video->fresh()->original_key)->not->toBeNull();
    Storage::disk('local')->assertExists($key);

    $event->update(['evidence_hold' => false]);
    $this->artisan('retention:cleanup')->assertSuccessful();

    expect($media->fresh()->state)->toBe('EXPIRED');
    Storage::disk('local')->assertMissing($key);
});

test('old observations expire while events and reviews remain', function () {
    [$run] = runningRun($this->video);
    $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()->subDays(10)]);
    $chunk = $run->directory().'/observations/000000.jsonl';
    Storage::disk('local')->put($chunk, '{}');
    $event = makeEvent($run);

    $this->artisan('retention:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing($chunk);
    expect($run->fresh()->result_manifest['observations_expired'])->toBeTrue()
        ->and($event->fresh())->not->toBeNull();

    $this->actingAs($this->owner)->getJson(route('analyses.observations', [$run, 'from_ms' => 0, 'to_ms' => 1000]))
        ->assertJsonPath('expired', true);
});

test('expired reports are removed', function () {
    $key = "tenants/{$this->tenant->id}/reports/r.csv";
    Storage::disk('local')->put($key, 'csv');
    $report = Report::query()->withoutGlobalScopes()->create([
        'tenant_id' => $this->tenant->id, 'format' => 'csv', 'status' => 'READY', 'filters' => [], 'cutoff_at' => now(),
        'disk' => 'local', 'key' => $key, 'expires_at' => now()->subDay(),
    ]);

    $this->artisan('retention:cleanup')->assertSuccessful();

    expect($report->fresh()->status)->toBe('EXPIRED');
    Storage::disk('local')->assertMissing($key);
});
