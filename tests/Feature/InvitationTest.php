<?php

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;

function inviteToken(User $owner, string $email, string $role = 'operator'): string
{
    Notification::fake();

    test()->actingAs($owner)->post(route('members.invite'), ['email' => $email, 'role' => $role])
        ->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return $token;
}

test('owner invites a member who accepts and joins with the invited role', function () {
    $owner = tenantUser();
    $tenant = tenantOf($owner);
    $token = inviteToken($owner, 'Koki@Example.com');

    expect(Invitation::query()->withoutGlobalScopes()->first())
        ->email->toBe('koki@example.com')
        ->token_hash->toBe(hash('sha256', $token));

    $invitee = User::factory()->create(['email' => 'koki@example.com']);

    $this->actingAs($invitee)->get(route('invitations.show', $token))->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitations/accept')
            ->where('invitation.usable', true)
            ->where('invitation.email_matches', true));

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));

    $membership = TenantMembership::query()->where('user_id', $invitee->id)->first();
    expect($membership->tenant_id)->toBe($tenant->id)
        ->and($membership->role)->toBe(Role::Operator);
});

test('a guest invitee registers without creating a tenant and returns to accept', function () {
    $owner = tenantUser();
    $tenant = tenantOf($owner);
    $token = inviteToken($owner, 'baru@example.com', 'supervisor');
    auth()->logout();

    $this->get(route('invitations.show', $token))->assertRedirect(route('login'));

    $this->get(route('register'))->assertInertia(fn ($page) => $page
        ->where('invitation.organization', $tenant->name)
        ->where('invitation.email', 'baru@example.com'));

    $this->post(route('register.store'), [
        'name' => 'Pegawai Baru', 'email' => 'baru@example.com',
        'password' => 'password', 'password_confirmation' => 'password', 'terms' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('invitations.show', $token));

    $invitee = User::query()->where('email', 'baru@example.com')->firstOrFail();
    expect(TenantMembership::query()->where('user_id', $invitee->id)->exists())->toBeFalse()
        ->and(Tenant::query()->count())->toBe(1)
        ->and($invitee->email_verified_at)->not->toBeNull();

    $this->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
    expect(TenantMembership::query()->where('user_id', $invitee->id)->first())
        ->tenant_id->toBe($tenant->id)
        ->role->toBe(Role::Supervisor);
});

test('registering from an invitation link with a different email still creates an organization', function () {
    $owner = tenantUser();
    $token = inviteToken($owner, 'baru@example.com');
    auth()->logout();
    $this->get(route('invitations.show', $token));

    $this->post(route('register.store'), [
        'name' => 'Orang Lain', 'email' => 'lain@example.com',
        'password' => 'password', 'password_confirmation' => 'password', 'terms' => '1',
    ])->assertSessionHasErrors('organization_name');
});

test('invitations are single use', function () {
    $owner = tenantUser();
    $token = inviteToken($owner, 'a@example.com');
    $invitee = User::factory()->create(['email' => 'a@example.com']);

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect();
    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertStatus(410);
});

test('invitations for another email cannot be accepted', function () {
    $owner = tenantUser();
    $token = inviteToken($owner, 'a@example.com');
    $stranger = User::factory()->create(['email' => 'b@example.com']);

    $this->actingAs($stranger)->post(route('invitations.accept', $token))->assertForbidden();
    expect(TenantMembership::query()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

test('expired invitations are rejected', function () {
    $owner = tenantUser();
    $token = inviteToken($owner, 'a@example.com');
    Invitation::query()->withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);
    $invitee = User::factory()->create(['email' => 'a@example.com']);

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertStatus(410);
});

test('owner role cannot be granted by invitation', function () {
    $owner = tenantUser();

    $this->actingAs($owner)->post(route('members.invite'), ['email' => 'x@example.com', 'role' => 'owner'])
        ->assertSessionHasErrors('role');
});

test('the last active owner cannot be demoted or suspended', function () {
    $owner = tenantUser();
    $membership = TenantMembership::query()->where('user_id', $owner->id)->first();

    $this->actingAs($owner)->patch(route('members.update', $membership), ['role' => 'viewer'])
        ->assertSessionHasErrors('role');
    $this->actingAs($owner)->patch(route('members.update', $membership), ['status' => 'suspended'])
        ->assertSessionHasErrors('role');

    expect($membership->fresh()->role)->toBe(Role::Owner);
});

test('owner can change a member role and it is audited', function () {
    $owner = tenantUser();
    $member = tenantUser(Role::Viewer, tenantOf($owner));
    $membership = TenantMembership::query()->where('user_id', $member->id)->first();

    $this->actingAs($owner)->patch(route('members.update', $membership), ['role' => 'supervisor'])
        ->assertSessionHasNoErrors();

    expect($membership->fresh()->role)->toBe(Role::Supervisor);
    $this->assertDatabaseHas('audit_logs', ['action' => 'member.updated', 'subject_id' => $membership->id]);
});
