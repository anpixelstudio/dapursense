<?php

namespace App\Http\Middleware;

use App\Models\AnalysisRun;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Worker callbacks carry a per-run token. The tenant comes from the verified
 * run, never from the payload.
 */
class AuthenticateWorkerRun
{
    public const MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        $token = (string) $request->bearerToken();
        $run = AnalysisRun::query()->withoutGlobalScopes()->find($request->route('run'));

        if (! $run || $token === '' || $run->worker_token_hash === null
            || ! hash_equals($run->worker_token_hash, hash('sha256', $token))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $this->context->setTenant($run->tenant_id);
        $request->attributes->set('run', $run);

        return $next($request);
    }
}
