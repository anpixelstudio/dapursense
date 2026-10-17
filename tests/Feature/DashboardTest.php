<?php

use App\Enums\Role;
use App\Models\ActivitySegment;
use App\Models\PersonTrack;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('members can visit the dashboard with tenant props shared', function () {
    $user = tenantUser(Role::Supervisor);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('tenant.role', 'supervisor')
            ->has('stats')
            ->has('trend', 7));
});

test('dashboard summarises people, zone time and rooms from the latest actual run per video', function () {
    $owner = tenantUser();
    $tenant = tenantOf($owner);
    $loc = makeLocation($tenant);
    $video = makeReadyVideo($loc['source']);
    $zone = Zone::query()->create(['tenant_id' => $tenant->id, 'source_id' => $loc['source']->id, 'name' => 'Kompor', 'type' => 'stove', 'points' => [[0, 0], [1, 0], [1, 1]], 'revision' => 1]);

    $addPerson = function ($run, string $label, ?string $zoneId, int $ms) use ($tenant) {
        $track = PersonTrack::query()->create(['tenant_id' => $tenant->id, 'run_id' => $run->id, 'track_key' => Str::random(8), 'first_ms' => 0, 'last_ms' => $ms]);
        ActivitySegment::query()->create(['tenant_id' => $tenant->id, 'run_id' => $run->id, 'track_id' => $track->id, 'zone_id' => $zoneId, 'label' => $label, 'start_ms' => 0, 'end_ms' => $ms]);
    };

    [$older] = runningRun($video, 'actual');
    $older->update(['status' => 'SUCCEEDED', 'finished_at' => now()->subHour()]);
    $addPerson($older, 'in_stove_zone', $zone->id, 99000);

    [$latest] = runningRun($video, 'actual');
    $latest->update(['status' => 'SUCCEEDED', 'finished_at' => now()]);
    $addPerson($latest, 'in_stove_zone', $zone->id, 4000);
    $addPerson($latest, 'outside_zones', null, 7000);
    makeEvent($latest, ['type' => 'restricted_zone']);
    makeEvent($latest, ['review_status' => 'DISMISSED']);

    [$simulation] = runningRun($video);
    $simulation->update(['status' => 'SUCCEEDED', 'finished_at' => now()->addMinute()]);
    $addPerson($simulation, 'in_stove_zone', $zone->id, 50000);

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('stats.people', 2)
            ->where('stats.work_zone_ms', 4000)
            ->where('stats.restricted', 1)
            ->where('stats.analysed_videos', 1)
            ->where('stats.needs_review', 1)
            ->where('trend.6.violations', 1)
            ->has('recentEvents', 1)
            ->has('rooms', 1)
            ->where('rooms.0.id', $loc['kitchen']->id)
            ->where('rooms.0.cameras', 1)
            ->where('rooms.0.online', 1)
            ->where('rooms.0.people', 2)
            ->where('rooms.0.open_events', 1)
            ->missing('health')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('health.active_sources', 1)
                ->has('health.storage_used_percent')));
});

test('activities default to the latest actual run even when a newer simulation exists', function () {
    $owner = tenantUser();
    $video = makeReadyVideo(makeLocation(tenantOf($owner))['source']);
    [$actual] = runningRun($video, 'actual');
    $actual->update(['status' => 'SUCCEEDED', 'finished_at' => now()]);
    [$simulation] = runningRun($video);
    $simulation->update(['status' => 'SUCCEEDED', 'finished_at' => now(), 'created_at' => now()->addMinute()]);

    $this->actingAs($owner)->get(route('activities.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('selectedRunId', $actual->id));
});

test('unverified users are sent to verification when verification is required', function () {
    $user = tenantUser();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('verification gate can be disabled for LAN deployments without marking email verified', function () {
    config(['kitchen.email_verification' => 'disabled']);
    $user = tenantUser();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('users without an active membership see the no-organization page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('organizations.none'));
    $this->actingAs($user)->get(route('organizations.none'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('organizations/none'));
});

test('every main page renders for an owner', function (string $route, string $component) {
    $user = tenantUser();

    $this->actingAs($user)->get(route($route))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
})->with([
    ['monitor', 'monitor/index'],
    ['locations.index', 'locations/index'],
    ['sources.index', 'sources/index'],
    ['videos.index', 'videos/index'],
    ['activities.index', 'activities/index'],
    ['events.index', 'events/index'],
    ['reports.index', 'reports/index'],
    ['rules.index', 'rules/index'],
    ['members.index', 'members/index'],
    ['notifications.index', 'notifications/index'],
    ['system.index', 'system/index'],
]);
