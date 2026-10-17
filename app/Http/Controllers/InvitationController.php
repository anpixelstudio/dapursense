<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Invitation;
use App\Models\TenantMembership;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);

        return Inertia::render('invitations/accept', [
            'token' => $token,
            'invitation' => $invitation ? [
                'organization' => $invitation->tenant->name,
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'usable' => $invitation->isUsable(),
                'email_matches' => strtolower($request->user()->email) === $invitation->email,
            ] : null,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        $membership = DB::transaction(function () use ($token, $user) {
            $invitation = Invitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            abort_if($invitation === null || ! $invitation->isUsable(), 410, 'Undangan tidak berlaku atau sudah dipakai.');
            abort_if(strtolower($user->email) !== $invitation->email, 403, 'Undangan ini untuk email lain.');

            $invitation->update(['accepted_at' => now()]);

            $membership = TenantMembership::query()->firstOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'status' => 'active'],
            );

            Audit::log('member.joined', $membership, ['role' => $invitation->role->value], $invitation->tenant_id);

            return $membership;
        });

        $request->session()->put(ResolveTenantContext::SESSION_KEY, $membership->tenant_id);
        $this->toast('Anda sekarang bergabung dengan organisasi.');

        return redirect()->route('dashboard');
    }

    private function find(string $token): ?Invitation
    {
        return Invitation::query()->with('tenant')->where('token_hash', hash('sha256', $token))->first();
    }
}
