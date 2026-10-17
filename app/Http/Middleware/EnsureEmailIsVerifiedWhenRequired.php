<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAN deployments without SMTP can set KITCHEN_EMAIL_VERIFICATION=disabled.
 * The email stays unverified in the database; only the gate is skipped.
 */
class EnsureEmailIsVerifiedWhenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('kitchen.email_verification') !== 'required') {
            return $next($request);
        }

        return app(EnsureEmailIsVerified::class)->handle($request, $next);
    }
}
