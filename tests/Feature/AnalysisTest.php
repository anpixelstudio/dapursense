<?php

use App\Domain\Analysis\DispatchRunJob;
use App\Domain\Analysis\WorkerClient;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\AnalysisRun;
use App\Models\SystemSetting;
use App\Models\Zone;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['ai-worker.test/*' => Http::response(['accepted' => true], 202)]);
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
    $this->loc = makeLocation($this->tenant);
    $this->video = makeReadyVideo($this->loc['source']);
    $this->ruleSet = defaultRuleSet($this->tenant);
});

function startRun(object $test, string $modelId, string $mode = 'simulation')
{
    return $test->actingAs($test->owner)->post(route('analyses.store', $test->video), [
        'model_id' => $modelId,
        'rule_set_id' => $test->ruleSet->id,
        'mode' => $mode,
    ]);
}

test('starting a simulation snapshots inputs and dispatches the run to the worker', function () {
    Zone::query()->create(['tenant_id' => $this->tenant->id, 'source_id' => $this->loc['source']->id, 'name' => 'Kompor', 'type' => 'stove', 'points' => [[0, 0], [1, 0], [1, 1]], 'revision' => 2]);

    startRun($this, simulatorModel()->id)->assertSessionHasNoErrors()->assertRedirect();

    $run = AnalysisRun::query()->withoutGlobalScopes()->firstOrFail();
    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->mode)->toBe('simulation')
        ->and($run->worker_token_hash)->toHaveLength(64)
        ->and($run->input_snapshot['zones'])->toHaveCount(1)
        ->and($run->input_snapshot['zones'][0]['revision'])->toBe(2)
        ->and($run->input_snapshot['rules']['checksum'])->toBe($this->ruleSet->activeRevision->checksum)
        ->and($run->rules_sha256)->toBe($this->ruleSet->activeRevision->checksum);

    Http::assertSent(function (Request $request) use ($run) {
        return $request->url() === 'http://ai-worker.test/runs'
            && $request->hasHeader('Authorization', 'Bearer test-service-token')
            && $request['run_id'] === $run->id
            && $request['tenant_id'] === $run->tenant_id
            && hash('sha256', $request['worker_token']) === $run->worker_token_hash
            && str_ends_with($request['callback_base'], "/internal/runs/{$run->id}");
    });
});

test('zone edits after starting do not change the run snapshot', function () {
    $zone = Zone::query()->create(['tenant_id' => $this->tenant->id, 'source_id' => $this->loc['source']->id, 'name' => 'Prep', 'type' => 'prep', 'points' => [[0, 0], [1, 0], [1, 1]], 'revision' => 1]);
    startRun($this, simulatorModel()->id);

    $this->actingAs($this->owner)->put(route('zones.update', $zone), ['name' => 'Prep', 'type' => 'prep', 'points' => [[0, 0], [0.5, 0], [0.5, 0.5]]]);

    $run = AnalysisRun::query()->withoutGlobalScopes()->firstOrFail();
    expect($run->input_snapshot['zones'][0]['points'])->toBe([[0, 0], [1, 0], [1, 1]]);
});

test('simulation mode requires the simulator and actual mode refuses it', function () {
    startRun($this, realModel()->id, 'simulation')->assertSessionHasErrors('model_id');
    startRun($this, simulatorModel()->id, 'actual')->assertSessionHasErrors('model_id');
    expect(AnalysisRun::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('only one run is RUNNING at a time; others stay queued', function () {
    startRun($this, simulatorModel()->id);
    startRun($this, simulatorModel()->id);

    expect(AnalysisRun::query()->withoutGlobalScopes()->where('status', 'RUNNING')->count())->toBe(1)
        ->and(AnalysisRun::query()->withoutGlobalScopes()->where('status', 'QUEUED')->count())->toBe(1);
});

test('a worker outage leaves the run queued for retry', function () {
    Queue::fake();
    startRun($this, simulatorModel()->id);
    $run = AnalysisRun::query()->withoutGlobalScopes()->firstOrFail();
    config(['kitchen.worker.url' => 'http://ai-down.test']);
    Http::fake(['ai-down.test/*' => Http::response('down', 503)]);

    expect(fn () => (new DispatchRunJob($run->id))->handle(app(WorkerClient::class)))->toThrow(RequestException::class);

    expect($run->fresh())->status->toBe(RunStatus::Queued)
        ->phase->toBe('waiting_worker')
        ->worker_token_hash->toBeNull();
});

test('the dispatch job marks the run failed after retries are exhausted', function () {
    [$run] = runningRun($this->video);
    $run->update(['status' => RunStatus::Queued]);

    (new DispatchRunJob($run->id))->failed(new RuntimeException('down'));

    expect($run->fresh())->status->toBe(RunStatus::Failed)->error_code->toBe('worker_unreachable');
});

test('analysis is refused while the system is paused for upgrade', function () {
    SystemSetting::put('analysis.paused', true);

    startRun($this, simulatorModel()->id)->assertSessionHasErrors('mode');
});

test('expired originals cannot be reanalysed', function () {
    $this->video->update(['original_key' => null]);

    startRun($this, simulatorModel()->id)->assertSessionHasErrors('video');
});

test('cancelling a queued run is immediate; a running run gets a cancel request', function () {
    [$running] = runningRun($this->video);
    startRun($this, simulatorModel()->id);
    $queued = AnalysisRun::query()->withoutGlobalScopes()->where('status', 'QUEUED')->firstOrFail();

    $this->actingAs($this->owner)->post(route('analyses.cancel', $queued))->assertSessionHasNoErrors();
    expect($queued->fresh()->status)->toBe(RunStatus::Cancelled);

    $this->actingAs($this->owner)->post(route('analyses.cancel', $running))->assertSessionHasNoErrors();
    expect($running->fresh())->status->toBe(RunStatus::Running)->cancel_requested_at->not->toBeNull();
    Http::assertSent(fn (Request $r) => $r->url() === "http://ai-worker.test/runs/{$running->id}/cancel");
});

test('operators cannot start analysis', function () {
    $operator = tenantUser(Role::Operator, $this->tenant);

    $this->actingAs($operator)->post(route('analyses.store', $this->video), [
        'model_id' => simulatorModel()->id, 'rule_set_id' => $this->ruleSet->id, 'mode' => 'simulation',
    ])->assertForbidden();
});

test('run status and observations are served from time-indexed chunks', function () {
    [$run] = runningRun($this->video);
    $disk = Storage::disk('local');
    $disk->put($run->directory().'/observations/000000.jsonl', implode("\n", [
        json_encode(['source_timestamp_ms' => 1000, 'detections' => []]),
        json_encode(['source_timestamp_ms' => 9000, 'detections' => []]),
    ]));
    $disk->put($run->directory().'/observations/000001.jsonl', json_encode(['source_timestamp_ms' => 12000, 'detections' => []]));

    $this->actingAs($this->owner)->getJson(route('analyses.status', $run))
        ->assertOk()->assertJsonPath('status', 'RUNNING');

    $this->actingAs($this->owner)->getJson(route('analyses.observations', [$run, 'from_ms' => 8000, 'to_ms' => 13000]))
        ->assertOk()
        ->assertJsonPath('expired', false)
        ->assertJsonCount(2, 'frames');
});

test('the analysis page renders with tracks and events', function () {
    [$run] = runningRun($this->video);

    $this->actingAs($this->owner)->get(route('analyses.show', $run))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('analyses/show')->has('tracks')->has('events'));
});
