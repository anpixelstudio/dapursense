<?php

use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\ActivitySegment;
use App\Models\AnomalyEvent;
use App\Models\EventMedia;
use App\Models\IngestionBatch;
use App\Models\PersonTrack;
use App\Models\Zone;
use App\Notifications\EventsNeedReview;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
    $this->loc = makeLocation($this->tenant);
    $this->video = makeReadyVideo($this->loc['source']);
    $this->zoneId = Zone::query()->create([
        'tenant_id' => $this->tenant->id, 'source_id' => $this->loc['source']->id, 'name' => 'Kompor',
        'type' => 'stove', 'points' => [[0, 0], [1, 0], [1, 1]], 'revision' => 1,
    ])->id;
    [$this->run, $this->token] = runningRun($this->video, 'simulation', [
        ['id' => $this->zoneId, 'name' => 'Kompor', 'type' => 'stove', 'revision' => 1, 'points' => [[0, 0], [1, 0], [1, 1]]],
    ]);
    $this->run->refresh();
});

function sendBatch(object $test, array $payload, ?string $token = null)
{
    return $test->withToken($token ?? $test->token)
        ->postJson(route('internal.runs.batches', $test->run), $payload);
}

test('a batch stores tracks, intervals and events idempotently', function () {
    $payload = batchPayload($this->run, 1, [
        'tracks' => [['track_key' => '1', 'first_ms' => 0, 'last_ms' => 8000]],
        'activity_segments' => [['track_key' => '1', 'zone_id' => $this->zoneId, 'label' => 'in_stove_zone', 'start_ms' => 0, 'end_ms' => 8000, 'score' => 0.9]],
        'ppe_assessments' => [['track_key' => '1', 'item_code' => 'hair_cover', 'required' => true, 'state' => 'missing', 'start_ms' => 2000, 'end_ms' => 8000, 'score' => 0.8]],
        'events' => [ppeEvent($this->run)],
    ]);

    sendBatch($this, $payload)->assertOk()->assertJsonPath('last_batch_sequence', 1);

    expect(PersonTrack::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(ActivitySegment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(AnomalyEvent::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($this->run->fresh()->last_batch_sequence)->toBe(1);

    $event = AnomalyEvent::query()->withoutGlobalScopes()->first();
    expect($event->is_simulation)->toBeTrue()->and($event->tenant_id)->toBe($this->tenant->id);

    sendBatch($this, $payload)->assertOk()->assertJsonPath('replayed', true);

    expect(AnomalyEvent::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(ActivitySegment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(IngestionBatch::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('the same sequence with a different payload fails the run with payload_conflict', function () {
    sendBatch($this, batchPayload($this->run, 1))->assertOk();

    sendBatch($this, batchPayload($this->run, 1, ['progress' => ['processed_ms' => 9999]]))
        ->assertStatus(409)->assertJsonPath('error', 'payload_conflict');

    expect($this->run->fresh())->status->toBe(RunStatus::Failed)->error_code->toBe('payload_conflict');
});

test('a sequence gap is rejected with the expected sequence', function () {
    sendBatch($this, batchPayload($this->run, 2))
        ->assertStatus(409)
        ->assertJsonPath('error', 'sequence_gap')
        ->assertJsonPath('expected_sequence', 1);

    expect($this->run->fresh()->status)->toBe(RunStatus::Running);
});

test('a batch from a stale attempt is rejected', function () {
    $this->run->update(['attempt' => 2]);

    sendBatch($this, batchPayload($this->run->fresh(), 1, ['attempt' => 1]))
        ->assertStatus(409)->assertJsonPath('error', 'stale_attempt');
});

test('invalid or foreign worker tokens get 401', function () {
    [$otherRun, $otherToken] = runningRun(makeReadyVideo(makeLocation(tenantOf(tenantUser()))['source']));

    sendBatch($this, batchPayload($this->run), 'wrong-token')->assertUnauthorized();
    sendBatch($this, batchPayload($this->run), $otherToken)->assertUnauthorized();
    $this->withoutToken()->postJson(route('internal.runs.batches', $this->run), batchPayload($this->run))->assertUnauthorized();
});

test('model or rules checksum mismatch is rejected', function () {
    sendBatch($this, batchPayload($this->run, 1, ['model_sha256' => str_repeat('a', 64)]))
        ->assertStatus(422)->assertJsonValidationErrors('model_sha256');
    sendBatch($this, batchPayload($this->run, 1, ['rules_sha256' => str_repeat('b', 64)]))
        ->assertStatus(422)->assertJsonValidationErrors('rules_sha256');
});

test('media keys outside the run directory are rejected', function () {
    $event = ppeEvent($this->run, ['media' => [['kind' => 'snapshot', 'state' => 'READY', 'key' => 'tenants/other/secret.jpg']]]);

    sendBatch($this, batchPayload($this->run, 1, ['events' => [$event]]))
        ->assertStatus(422)->assertJsonValidationErrors('events.0.media.0.key');

    $traversal = ppeEvent($this->run, ['media' => [['kind' => 'snapshot', 'state' => 'READY', 'key' => $this->run->directory().'/../../x.jpg']]]);
    sendBatch($this, batchPayload($this->run, 1, ['events' => [$traversal]]))->assertStatus(422);
});

test('unknown zones in activity segments are rejected', function () {
    sendBatch($this, batchPayload($this->run, 1, [
        'activity_segments' => [['track_key' => '1', 'zone_id' => (string) str()->uuid(), 'label' => 'in_prep_zone', 'start_ms' => 0, 'end_ms' => 1000]],
    ]))->assertStatus(422);
});

test('an episode spanning batches updates one event instead of duplicating it', function () {
    sendBatch($this, batchPayload($this->run, 1, ['events' => [ppeEvent($this->run, ['last_ms' => 6000, 'lifecycle' => 'active'])]]))->assertOk();
    sendBatch($this, batchPayload($this->run, 2, ['events' => [ppeEvent($this->run, ['first_ms' => 6000, 'last_ms' => 15000, 'lifecycle' => 'closed', 'peak_score' => 0.95])]]))->assertOk();

    $events = AnomalyEvent::query()->withoutGlobalScopes()->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->first_ms)->toBe(2000)
        ->and($events[0]->last_ms)->toBe(15000)
        ->and($events[0]->lifecycle)->toBe('closed')
        ->and($events[0]->peak_score)->toBe(0.95);
});

test('completion is refused while evidence is pending', function () {
    $event = ppeEvent($this->run, ['media' => [['kind' => 'clip', 'state' => 'PENDING']]]);
    sendBatch($this, batchPayload($this->run, 1, ['events' => [$event]]))->assertOk();

    $this->withToken($this->token)->postJson(route('internal.runs.complete', $this->run), [
        'attempt' => 1, 'status' => 'succeeded', 'final_batch_sequence' => 1,
    ])->assertStatus(409)->assertJsonPath('error', 'evidence_pending');

    expect($this->run->fresh()->status)->toBe(RunStatus::Running);
});

test('completion requires every batch to have been received', function () {
    sendBatch($this, batchPayload($this->run, 1))->assertOk();

    $this->withToken($this->token)->postJson(route('internal.runs.complete', $this->run), [
        'attempt' => 1, 'status' => 'succeeded', 'final_batch_sequence' => 2,
    ])->assertStatus(409)->assertJsonPath('expected_sequence', 2);
});

test('successful completion closes events, sets retention and notifies reviewers in-app', function () {
    Notification::fake();
    $supervisor = tenantUser(Role::Supervisor, $this->tenant);
    $viewer = tenantUser(Role::Viewer, $this->tenant);

    sendBatch($this, batchPayload($this->run, 1, ['events' => [ppeEvent($this->run)]]))->assertOk();

    $this->withToken($this->token)->postJson(route('internal.runs.complete', $this->run), [
        'attempt' => 1, 'status' => 'succeeded', 'final_batch_sequence' => 1,
        'manifest' => ['frames' => 60, 'observation_chunks' => 2],
    ])->assertOk()->assertJsonPath('status', 'SUCCEEDED');

    $run = $this->run->fresh();
    expect($run)->status->toBe(RunStatus::Succeeded)->progress->toBe(100)
        ->and($run->result_manifest['frames'])->toBe(60)
        ->and(AnomalyEvent::query()->withoutGlobalScopes()->first()->lifecycle)->toBe('closed')
        ->and($this->video->fresh()->original_expires_at)->not->toBeNull();

    Notification::assertSentTo([$this->owner, $supervisor], EventsNeedReview::class);
    Notification::assertNotSentTo($viewer, EventsNeedReview::class);
});

test('late callbacks after completion are rejected', function () {
    $this->withToken($this->token)->postJson(route('internal.runs.complete', $this->run), [
        'attempt' => 1, 'status' => 'succeeded', 'final_batch_sequence' => 0,
    ])->assertOk();

    sendBatch($this, batchPayload($this->run, 1))->assertStatus(409)->assertJsonPath('error', 'run_not_active');
});

test('a failed source_unreadable completion creates a system event', function () {
    $this->withToken($this->token)->postJson(route('internal.runs.complete', $this->run), [
        'attempt' => 1, 'status' => 'failed', 'final_batch_sequence' => 0,
        'error_code' => 'source_unreadable', 'error_message' => 'Decoder error',
    ])->assertOk();

    expect(AnomalyEvent::query()->withoutGlobalScopes()->where('type', 'source_unreadable')->where('category', 'system')->exists())->toBeTrue();
});

test('heartbeat updates progress and reports cancel requests', function () {
    $this->withToken($this->token)->postJson(route('internal.runs.heartbeat', $this->run), [
        'attempt' => 1, 'processed_ms' => 10000, 'phase' => 'inference',
    ])->assertOk()->assertJsonPath('cancel_requested', false);

    expect($this->run->fresh()->progress)->toBe(50);

    $this->run->update(['cancel_requested_at' => now()]);
    $this->withToken($this->token)->postJson(route('internal.runs.heartbeat', $this->run), ['attempt' => 1])
        ->assertOk()->assertJsonPath('cancel_requested', true);
});

test('media states are stored per event and kind', function () {
    $event = ppeEvent($this->run, ['media' => [
        ['kind' => 'snapshot', 'state' => 'READY', 'key' => $this->run->directory().'/evidence/a.jpg'],
        ['kind' => 'clip', 'state' => 'FAILED', 'error_code' => 'ffmpeg_failed'],
    ]]);
    sendBatch($this, batchPayload($this->run, 1, ['events' => [$event]]))->assertOk();

    expect(EventMedia::query()->withoutGlobalScopes()->where('kind', 'clip')->first())
        ->state->toBe('FAILED')->error_code->toBe('ffmpeg_failed');
});

test('the internal health endpoint requires the service token', function () {
    $this->getJson(route('internal.health'))->assertUnauthorized();
    $this->withToken('test-service-token')->getJson(route('internal.health'))->assertOk()->assertJsonPath('ok', true);
});
