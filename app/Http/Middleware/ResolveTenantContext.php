<?php

namespace App\Http\Middleware;

use App\Models\TenantMembership;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The active tenant comes from the session and is re-validated against an
 * active membership on every request; tenant ids sent by the browser are never trusted.
 */
class ResolveTenantContext
{
    public const SESSION_KEY = 'current_tenant_id';

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();

        if ($user) {
            $memberships = $user->activeMemberships()->with('tenant')->get();
            $membership = $memberships->firstWhere('tenant_id', $request->session()->get(self::SESSION_KEY))
                ?? $memberships->first();

            if ($membership instanceof TenantMembership) {
                $request->session()->put(self::SESSION_KEY, $membership->tenant_id);
                $this->context->set($membership);
            } else {
                $request->session()->forget(self::SESSION_KEY);
                $this->context->clear();
            }
        }

        if ($mode === 'required' && ! $this->context->has()) {
            if ($request->expectsJson()) {
                abort(403, 'No active organization for this account.');
            }

            return redirect()->route('organizations.none');
        }

        return $next($request);
    }
}
