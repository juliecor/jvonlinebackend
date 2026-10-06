<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buyers and staff never call Laravel directly: the Next.js server does, so
 * every request would carry the Next server's address and share one rate
 * limit. Next passes the visitor's address in X-Client-IP together with a
 * shared secret; only then is it used as the request's IP (rate limits, the IP
 * stored on offer responses).
 */
class TrustFrontendClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('app.internal_key');
        $ip = (string) $request->header('X-Client-IP');
        if ($key !== '' && hash_equals($key, (string) $request->header('X-Internal-Key')) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        return $next($request);
    }
}
