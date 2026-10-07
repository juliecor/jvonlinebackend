<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only jvconline admins past this point (routes under /api/admin). Super admins
 * keep these pages while they view a realty's dashboard as its admin or an agent.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->isAdmin() && ! $user?->isSuperAdmin()) {
            abort(403, 'Admins only.');
        }

        return $next($request);
    }
}
