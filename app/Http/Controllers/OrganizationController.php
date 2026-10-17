<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function none(Request $request): Response|RedirectResponse
    {
        if ($request->user()->activeMemberships()->exists()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('organizations/none');
    }

    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'uuid']]);

        $membership = $request->user()->activeMemberships()
            ->where('tenant_id', $validated['tenant_id'])
            ->first();

        abort_if($membership === null, 403, 'Anda bukan anggota aktif organisasi tersebut.');

        $request->session()->put(ResolveTenantContext::SESSION_KEY, $membership->tenant_id);

        return redirect()->route('dashboard');
    }
}
