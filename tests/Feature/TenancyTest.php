<?php

use App\Enums\Role;
use App\Models\Property;
use App\Models\TenantMembership;

test('a user only sees data from the active tenant', function () {
    $alice = tenantUser();
    $bob = tenantUser();
    makeLocation(tenantOf($alice));
    makeLocation(tenantOf($bob));

    $this->actingAs($alice)->get(route('locations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('properties', 1)
            ->where('properties.0.tenant_id', tenantOf($alice)->id));
});

test('cross-tenant objects return 404 instead of 403', function () {
    $alice = tenantUser();
    $bob = tenantUser();
    $foreign = makeLocation(tenantOf($bob));
    $video = makeReadyVideo($foreign['source']);

    $this->actingAs($alice)->get(route('videos.show', $video))->assertNotFound();
    $this->actingAs($alice)->put(route('properties.update', $foreign['property']), [
        'name' => 'Diambil alih', 'timezone' => 'Asia/Jakarta',
    ])->assertNotFound();

    expect(Property::query()->withoutGlobalScopes()->find($foreign['property']->id)->name)->toBe('Properti');
});

test('foreign ids in payloads fail validation', function () {
    $alice = tenantUser();
    $foreign = makeLocation(tenantOf(tenantUser()));

    $this->actingAs($alice)->post(route('kitchens.store'), [
        'property_id' => $foreign['property']->id, 'name' => 'Dapur Palsu',
    ])->assertSessionHasErrors('property_id');
});

test('switching tenant only works for active memberships', function () {
    $user = tenantUser();
    $other = tenantOf(tenantUser());
    $membership = TenantMembership::query()->create(['tenant_id' => $other->id, 'user_id' => $user->id, 'role' => Role::Viewer]);

    $this->actingAs($user)->post(route('tenants.switch'), ['tenant_id' => $other->id])->assertRedirect();
    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('tenant.id', $other->id)->where('tenant.role', 'viewer'));

    $membership->update(['status' => 'suspended']);
    $this->actingAs($user)->post(route('tenants.switch'), ['tenant_id' => $other->id])->assertForbidden();
});

test('suspended members lose access to the tenant', function () {
    $user = tenantUser(Role::Supervisor);
    TenantMembership::query()->where('user_id', $user->id)->update(['status' => 'suspended']);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('organizations.none'));
});

test('role permissions are enforced on mutations', function (Role $role, bool $canManageLocations, bool $canInvite) {
    $owner = tenantUser();
    $tenant = tenantOf($owner);
    $user = tenantUser($role, $tenant);

    $response = $this->actingAs($user)->post(route('properties.store'), ['name' => 'Gedung B', 'timezone' => 'Asia/Jakarta']);
    $canManageLocations ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();

    $response = $this->actingAs($user)->post(route('members.invite'), ['email' => 'baru@example.com', 'role' => 'viewer']);
    $canInvite ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'owner' => [Role::Owner, true, true],
    'supervisor' => [Role::Supervisor, true, false],
    'operator' => [Role::Operator, false, false],
    'viewer' => [Role::Viewer, false, false],
]);
