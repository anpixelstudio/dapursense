<?php

use App\Enums\Role;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Models\Property;
use App\Models\Zone;

beforeEach(function () {
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
});

test('owner builds the property, kitchen and source hierarchy', function () {
    $this->actingAs($this->owner)->post(route('properties.store'), ['name' => 'Hotel Bali', 'timezone' => 'Asia/Makassar'])
        ->assertSessionHasNoErrors();
    $property = Property::query()->withoutGlobalScopes()->where('name', 'Hotel Bali')->firstOrFail();
    expect($property->tenant_id)->toBe($this->tenant->id);

    $this->actingAs($this->owner)->post(route('kitchens.store'), ['property_id' => $property->id, 'name' => 'Pastry'])
        ->assertSessionHasNoErrors();
    $kitchen = Kitchen::query()->withoutGlobalScopes()->where('name', 'Pastry')->firstOrFail();

    $this->actingAs($this->owner)->post(route('sources.store'), ['kitchen_id' => $kitchen->id, 'name' => 'Kamera Pastry', 'type' => 'upload'])
        ->assertSessionHasNoErrors();
    expect(MediaSource::query()->withoutGlobalScopes()->where('kitchen_id', $kitchen->id)->count())->toBe(1);
});

test('rtsp sources are not available in P0', function () {
    $loc = makeLocation($this->tenant);

    $this->actingAs($this->owner)->post(route('sources.store'), ['kitchen_id' => $loc['kitchen']->id, 'name' => 'CCTV', 'type' => 'rtsp'])
        ->assertSessionHasErrors('type');
});

test('a property with kitchens and a kitchen with sources cannot be deleted', function () {
    $loc = makeLocation($this->tenant);

    $this->actingAs($this->owner)->delete(route('properties.destroy', $loc['property']))->assertSessionHasErrors('property');
    $this->actingAs($this->owner)->delete(route('kitchens.destroy', $loc['kitchen']))->assertSessionHasErrors('kitchen');
});

test('a source with videos cannot be deleted', function () {
    $loc = makeLocation($this->tenant);
    makeReadyVideo($loc['source']);

    $this->actingAs($this->owner)->delete(route('sources.destroy', $loc['source']))->assertSessionHasErrors('source');
});

test('zones are stored per source and geometry changes bump the revision', function () {
    $loc = makeLocation($this->tenant);
    $points = [[0.1, 0.1], [0.5, 0.1], [0.5, 0.6]];

    $this->actingAs($this->owner)->post(route('zones.store', $loc['source']), ['name' => 'Kompor', 'type' => 'stove', 'points' => $points])
        ->assertSessionHasNoErrors();
    $zone = Zone::query()->withoutGlobalScopes()->firstOrFail();
    expect($zone->revision)->toBe(1)->and($zone->source_id)->toBe($loc['source']->id);

    $this->actingAs($this->owner)->put(route('zones.update', $zone), ['name' => 'Kompor Utama', 'type' => 'stove', 'points' => $points])
        ->assertSessionHasNoErrors();
    expect($zone->fresh()->revision)->toBe(1);

    $this->actingAs($this->owner)->put(route('zones.update', $zone), ['name' => 'Kompor Utama', 'type' => 'stove', 'points' => [[0.2, 0.1], [0.5, 0.1], [0.5, 0.6]]])
        ->assertSessionHasNoErrors();
    expect($zone->fresh()->revision)->toBe(2);
});

test('zone polygons are validated', function (array $points) {
    $loc = makeLocation($this->tenant);

    $this->actingAs($this->owner)->post(route('zones.store', $loc['source']), ['name' => 'X', 'type' => 'prep', 'points' => $points])
        ->assertSessionHasErrors();
})->with([
    'too few points' => [[[0.1, 0.1], [0.2, 0.2]]],
    'out of range' => [[[0.1, 0.1], [1.5, 0.2], [0.3, 0.3]]],
    'wrong shape' => [[[0.1], [0.2, 0.2], [0.3, 0.3]]],
]);

test('deleting a zone only deactivates it', function () {
    $loc = makeLocation($this->tenant);
    $zone = Zone::query()->create(['tenant_id' => $this->tenant->id, 'source_id' => $loc['source']->id, 'name' => 'Cuci', 'type' => 'sink', 'points' => [[0, 0], [1, 0], [1, 1]], 'revision' => 1]);

    $this->actingAs($this->owner)->delete(route('zones.destroy', $zone))->assertSessionHasNoErrors();
    expect($zone->fresh()->active)->toBeFalse();
});

test('zones are duplicated to another source but not to the same one', function () {
    $loc = makeLocation($this->tenant);
    $other = MediaSource::query()->create(['tenant_id' => $this->tenant->id, 'kitchen_id' => $loc['kitchen']->id, 'name' => 'Kamera 2', 'type' => 'upload']);
    $zone = Zone::query()->create(['tenant_id' => $this->tenant->id, 'source_id' => $loc['source']->id, 'name' => 'Prep', 'type' => 'prep', 'points' => [[0, 0], [1, 0], [1, 1]], 'revision' => 3]);

    $this->actingAs($this->owner)->post(route('zones.duplicate', $zone), ['target_source_id' => $loc['source']->id])
        ->assertSessionHasErrors('target_source_id');

    $this->actingAs($this->owner)->post(route('zones.duplicate', $zone), ['target_source_id' => $other->id])
        ->assertSessionHasNoErrors();
    $copy = Zone::query()->withoutGlobalScopes()->where('source_id', $other->id)->firstOrFail();
    expect($copy->revision)->toBe(1)->and($copy->points)->toBe($zone->points);
});

test('operators cannot edit zones', function () {
    $loc = makeLocation($this->tenant);
    $operator = tenantUser(Role::Operator, $this->tenant);

    $this->actingAs($operator)->post(route('zones.store', $loc['source']), ['name' => 'X', 'type' => 'prep', 'points' => [[0, 0], [1, 0], [1, 1]]])
        ->assertForbidden();
});

test('rule publishing creates an immutable new revision', function () {
    $ruleSet = defaultRuleSet($this->tenant);
    $first = $ruleSet->activeRevision;
    $config = $first->config;
    $config['thresholds']['cooldown_ms'] = 12000;

    $this->actingAs($this->owner)->post(route('rules.publish', $ruleSet), ['config' => $config])->assertSessionHasNoErrors();

    $ruleSet->refresh()->load('activeRevision');
    expect($ruleSet->activeRevision->version)->toBe($first->version + 1)
        ->and($ruleSet->activeRevision->checksum)->not->toBe($first->checksum)
        ->and($first->fresh()->config)->toBe($first->config);

    expect(fn () => $first->update(['config' => []]))->toThrow(LogicException::class);
});
