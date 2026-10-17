<?php

use App\Enums\Role;
use App\Enums\VideoStatus;
use App\Models\MediaSource;
use Inertia\Testing\AssertableInertia;

test('monitor groups cameras by room and replays each latest ready recording with its best run', function () {
    $owner = tenantUser(Role::Viewer);
    $tenant = tenantOf($owner);
    $loc = makeLocation($tenant);
    $idle = MediaSource::query()->create(['tenant_id' => $tenant->id, 'kitchen_id' => $loc['kitchen']->id, 'name' => 'Kamera Gudang', 'type' => 'upload']);

    makeReadyVideo($loc['source'], ['created_at' => now()->subDay()]);
    $latest = makeReadyVideo($loc['source']);
    makeReadyVideo($loc['source'], ['status' => VideoStatus::Validating, 'created_at' => now()->addMinute()]);

    $zone = ['id' => 'z1', 'name' => 'Kompor', 'type' => 'stove', 'points' => [[0, 0], [1, 0], [0.5, 1]], 'revision' => 1];
    [$simulation] = runningRun($latest);
    $simulation->update(['status' => 'SUCCEEDED', 'finished_at' => now()]);
    [$actual] = runningRun($latest, 'actual', [$zone]);
    $actual->update(['status' => 'SUCCEEDED', 'finished_at' => now()->subHour()]);
    makeEvent($actual);
    makeEvent($simulation);

    $other = tenantUser();
    makeReadyVideo(makeLocation(tenantOf($other))['source']);

    $this->actingAs($owner)->get(route('monitor'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitor/index')
            ->has('properties', 1)
            ->has('properties.0.rooms', 1)
            ->has('properties.0.rooms.0.cameras', 2)
            ->where('properties.0.rooms.0.cameras.0.id', $loc['source']->id)
            ->where('properties.0.rooms.0.cameras.0.video.id', $latest->id)
            ->where('properties.0.rooms.0.cameras.0.run.id', $actual->id)
            ->where('properties.0.rooms.0.cameras.0.zones.0.name', 'Kompor')
            ->where('properties.0.rooms.0.cameras.0.open_events', 1)
            ->has('properties.0.rooms.0.cameras.0.recent_events', 2)
            ->where('properties.0.rooms.0.cameras.1.id', $idle->id)
            ->where('properties.0.rooms.0.cameras.1.video', null)
            ->where('properties.0.rooms.0.cameras.1.run', null));
});
