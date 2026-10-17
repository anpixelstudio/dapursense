<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\TenantMembership;
use App\Notifications\InvitationNotification;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(): Response
    {
        $this->authorizePermission(Permission::ViewData);
        $tenantId = $this->tenantId();

        return Inertia::render('members/index', [
            'members' => TenantMembership::query()
                ->where('tenant_id', $tenantId)
                ->with('user:id,name,email')
                ->orderBy('created_at')
                ->get()
                ->map(fn (TenantMembership $m) => [
                    'id' => $m->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'role' => $m->role->value,
                    'status' => $m->status,
                    'joined_at' => $m->created_at,
                ]),
            'invitations' => Invitation::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('accepted_at')
                ->orderByDesc('created_at')
                ->get(['id', 'email', 'role', 'expires_at', 'created_at']),
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases()),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageMembers);
        $tenantId = $this->tenantId();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->except([Role::Owner])],
        ]);

        $alreadyMember = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('user', fn ($q) => $q->where('email', strtolower($validated['email'])))
            ->exists();

        if ($alreadyMember) {
            return back()->withErrors(['email' => 'This email is already an organization member.']);
        }

        $token = Str::random(48);

        $invitation = Invitation::query()->create([
            'tenant_id' => $tenantId,
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'token_hash' => hash('sha256', $token),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationNotification($invitation, $token));

        Audit::log('member.invited', $invitation, ['email' => $invitation->email, 'role' => $validated['role']]);

        $this->toast('Invitation created. Link: '.route('invitations.show', $token));

        return back()->with('invitation_url', route('invitations.show', $token));
    }

    public function revokeInvitation(Invitation $invitation): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageMembers, $invitation);

        $invitation->delete();
        Audit::log('member.invitation_revoked', $invitation);
        $this->toast('Invitation cancelled.');

        return back();
    }

    public function update(Request $request, TenantMembership $membership): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageMembers, $membership);

        $validated = $request->validate([
            'role' => ['sometimes', Rule::enum(Role::class)],
            'status' => ['sometimes', 'in:active,suspended'],
        ]);

        $wouldRemoveOwner = $membership->role === Role::Owner
            && (($validated['role'] ?? 'owner') !== 'owner' || ($validated['status'] ?? 'active') !== 'active');

        if ($wouldRemoveOwner) {
            $otherOwners = TenantMembership::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('role', Role::Owner->value)
                ->where('status', 'active')
                ->whereKeyNot($membership->id)
                ->exists();

            if (! $otherOwners) {
                return back()->withErrors(['role' => 'The organization must have at least one active Owner.']);
            }
        }

        $before = ['role' => $membership->role->value, 'status' => $membership->status];
        $membership->update($validated);
        Audit::log('member.updated', $membership, ['before' => $before, 'after' => $validated]);
        $this->toast('Membership updated.');

        return back();
    }
}
