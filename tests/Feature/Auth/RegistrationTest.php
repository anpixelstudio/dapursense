<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\RuleSet;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;

test('registration screen can be rendered', function () {
    $this->get(route('register'))->assertOk();
});

test('registration creates user, tenant, owner membership and default SOP in one go', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Budi Supervisor',
        'email' => 'budi@hotel.test',
        'organization_name' => 'Hotel Anpixel Jakarta',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'budi@hotel.test')->firstOrFail();
    $tenant = Tenant::query()->where('name', 'Hotel Anpixel Jakarta')->firstOrFail();

    expect(TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->first()->role)->toBe(Role::Owner)
        ->and($tenant->slug)->toBe('hotel-anpixel-jakarta')
        ->and(RuleSet::query()->where('tenant_id', $tenant->id)->first()->active_revision_id)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'tenant.registered')->where('tenant_id', $tenant->id)->exists())->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();
});

test('registration requires organization name and consent', function () {
    $this->post(route('register.store'), [
        'name' => 'X',
        'email' => 'x@hotel.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors(['organization_name', 'terms']);

    expect(User::query()->count())->toBe(0)->and(Tenant::query()->count())->toBe(0);
});

test('email must be globally unique', function () {
    User::factory()->create(['email' => 'dup@hotel.test']);

    $this->post(route('register.store'), [
        'name' => 'Y', 'email' => 'dup@hotel.test', 'organization_name' => 'Org',
        'password' => 'password', 'password_confirmation' => 'password', 'terms' => '1',
    ])->assertSessionHasErrors('email');

    expect(Tenant::query()->count())->toBe(0);
});

test('two registrations get distinct slugs for the same organization name', function () {
    foreach (['a@hotel.test', 'b@hotel.test'] as $email) {
        $this->post(route('register.store'), [
            'name' => 'U', 'email' => $email, 'organization_name' => 'Hotel Sama',
            'password' => 'password', 'password_confirmation' => 'password', 'terms' => '1',
        ]);
        auth()->logout();
    }

    expect(Tenant::query()->pluck('slug')->unique())->toHaveCount(2);
});
