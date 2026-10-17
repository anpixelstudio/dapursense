<?php

use App\Enums\EventCategory;
use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Models\EventMedia;
use App\Models\EventReview;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
    $this->supervisor = tenantUser(Role::Supervisor, $this->tenant);
    $video = makeReadyVideo(makeLocation($this->tenant)['source']);
    [$this->run] = runningRun($video);
    $this->event = makeEvent($this->run);
});

function review(object $test, $user, array $data, bool $json = false)
{
    $data += ['expected_revision' => $test->event->fresh()->revision];

    return $json
        ? $test->actingAs($user)->patchJson(route('events.review', $test->event), $data)
        : $test->actingAs($user)->patch(route('events.review', $test->event), $data);
}

test('a violation goes OPEN to CONFIRMED to RESOLVED with history and revisions', function () {
    review($this, $this->supervisor, ['to_status' => 'CONFIRMED', 'reason' => 'Terlihat jelas tanpa penutup rambut'])->assertSessionHasNoErrors();
    review($this, $this->supervisor, ['to_status' => 'RESOLVED', 'action_note' => 'Staf diberi teguran dan hairnet'])->assertSessionHasNoErrors();

    $event = $this->event->fresh();
    expect($event->review_status)->toBe(ReviewStatus::Resolved)
        ->and($event->revision)->toBe(3)
        ->and($event->last_reviewed_by)->toBe($this->supervisor->id)
        ->and(EventReview::query()->withoutGlobalScopes()->where('event_id', $event->id)->pluck('to_status')->all())
        ->toBe(['CONFIRMED', 'RESOLVED']);

    $this->assertDatabaseHas('audit_logs', ['action' => 'event.reviewed', 'subject_id' => $event->id]);
});

test('required fields are enforced per transition', function (string $to, array $missing) {
    review($this, $this->supervisor, ['to_status' => $to])->assertSessionHasErrors($missing);
    expect($this->event->fresh()->review_status)->toBe(ReviewStatus::Open);
})->with([
    'confirm needs reason' => ['CONFIRMED', ['reason']],
    'dismiss needs code and note' => ['DISMISSED', ['reason_code', 'reason']],
]);

test('resolving requires an action note', function () {
    review($this, $this->supervisor, ['to_status' => 'CONFIRMED', 'reason' => 'Valid']);

    review($this, $this->supervisor, ['to_status' => 'RESOLVED'])->assertSessionHasErrors('action_note');
});

test('invalid transitions are rejected', function () {
    review($this, $this->supervisor, ['to_status' => 'RESOLVED', 'action_note' => 'x'])->assertSessionHasErrors('to_status');
});

test('system events cannot be confirmed as violations', function () {
    $this->event->update(['category' => EventCategory::System, 'type' => 'analysis_stalled']);

    review($this, $this->supervisor, ['to_status' => 'CONFIRMED', 'reason' => 'x'])->assertSessionHasErrors('to_status');
    review($this, $this->supervisor, ['to_status' => 'ACKNOWLEDGED'])->assertSessionHasNoErrors();
    review($this, $this->supervisor, ['to_status' => 'RESOLVED', 'action_note' => 'Worker direstart'])->assertSessionHasNoErrors();
});

test('a stale revision returns 409 with the current decision', function () {
    $stale = $this->event->revision;
    review($this, $this->owner, ['to_status' => 'ACKNOWLEDGED'])->assertSessionHasNoErrors();

    review($this, $this->supervisor, ['to_status' => 'DISMISSED', 'reason_code' => 'false_detection', 'reason' => 'Bayangan', 'expected_revision' => $stale], json: true)
        ->assertStatus(409)
        ->assertJsonPath('error', 'revision_conflict')
        ->assertJsonPath('current.review_status', 'ACKNOWLEDGED')
        ->assertJsonPath('current.last_reviewed_by', $this->owner->name);

    expect($this->event->fresh()->review_status)->toBe(ReviewStatus::Acknowledged);
});

test('reopening requires owner or supervisor and a reason', function () {
    $this->event->update(['review_status' => ReviewStatus::Dismissed]);

    review($this, $this->supervisor, ['to_status' => 'OPEN'])->assertSessionHasErrors('reason');
    review($this, $this->supervisor, ['to_status' => 'OPEN', 'reason' => 'Perlu dicek ulang'])->assertSessionHasNoErrors();
    expect($this->event->fresh()->review_status)->toBe(ReviewStatus::Open);
});

test('operators and viewers cannot review', function (Role $role) {
    $user = tenantUser($role, $this->tenant);

    review($this, $user, ['to_status' => 'ACKNOWLEDGED'])->assertForbidden();
})->with([Role::Operator, Role::Viewer]);

test('reviewers from another tenant get 404', function () {
    review($this, tenantUser(Role::Supervisor), ['to_status' => 'ACKNOWLEDGED'])->assertNotFound();
});

test('the event page shows allowed transitions and media', function () {
    $this->actingAs($this->supervisor)->get(route('events.show', $this->event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('events/show')
            ->where('event.id', $this->event->id)
            ->has('allowedTransitions', 3));
});

test('evidence hold is toggled and audited', function () {
    $this->actingAs($this->supervisor)->post(route('events.hold', $this->event), ['hold' => true])->assertSessionHasNoErrors();

    expect($this->event->fresh()->evidence_hold)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'event.hold_set', 'subject_id' => $this->event->id]);
});

test('evidence media is served privately only when READY', function () {
    Storage::fake('local');
    $key = $this->run->directory().'/evidence/snap.jpg';
    Storage::disk('local')->put($key, 'jpeg-bytes');
    $ready = EventMedia::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'kind' => 'snapshot', 'disk' => 'local', 'key' => $key, 'state' => 'READY']);
    $failed = EventMedia::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'kind' => 'clip', 'disk' => 'local', 'state' => 'FAILED', 'error_code' => 'ffmpeg_failed']);

    $this->actingAs($this->supervisor)->get(route('events.media', [$this->event, $ready]))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->supervisor)->get(route('events.media', [$this->event, $failed]))->assertNotFound();
    $this->actingAs(tenantUser())->get(route('events.media', [$this->event, $ready]))->assertNotFound();
});

test('the anomaly list filters simulation events', function () {
    makeEvent($this->run, ['is_simulation' => false]);

    $this->actingAs($this->supervisor)->get(route('events.index', ['simulation' => 'exclude']))
        ->assertInertia(fn ($page) => $page->component('events/index')->has('events.data', 1));
    $this->actingAs($this->supervisor)->get(route('events.index'))
        ->assertInertia(fn ($page) => $page->has('events.data', 2));
});
