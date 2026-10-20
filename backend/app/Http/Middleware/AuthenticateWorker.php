<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('dataflow.internal_service_token');
        $provided = $request->bearerToken();
        if (! is_string($expected) || strlen($expected) < 32 || $expected === 'generate-locally-do-not-commit' ||
            ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Worker authentication required']], 401)->header('Cache-Control', 'no-store');
        }
        if (strlen($request->getContent()) > 2048) {
            return response()->json(['error' => ['code' => 'PAYLOAD_TOO_LARGE', 'message' => 'Request too large']], 413)->header('Cache-Control', 'no-store');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
