<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorkerService
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('kitchen.worker.service_token');

        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
