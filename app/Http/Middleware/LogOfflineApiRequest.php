<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log every request from the mobile offline app (routes using this middleware).
 */
class LogOfflineApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        Log::channel('single')->info('Novustream offline API: request', [
            'method'     => $request->method(),
            'path'       => $request->path(),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $response = $next($request);

        $user = $request->user();
        Log::channel('single')->info('Novustream offline API: response', [
            'method'      => $request->method(),
            'path'        => $request->path(),
            'status'      => $response->getStatusCode(),
            'admin_id'    => $user?->id,
            'duration_ms' => round((microtime(true) - $start) * 1000),
        ]);

        return $response;
    }
}
