<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes under /api/realty: realty staff and agents, each scoped to their own
 * realty. `realty.member` lets both in; `realty.member:staff` only the staff.
 */
class EnsureRealtyMember
{
    public function handle(Request $request, Closure $next, string $level = 'any'): Response
    {
        $user = $request->user();
        if (! $user || ! $user->realty_id || ! in_array($user->role, [User::ROLE_REALTY, User::ROLE_AGENT], true)) {
            abort(403, 'Realty accounts only.');
        }
        if ($level === 'staff' && $user->role !== User::ROLE_REALTY) {
            abort(403, 'Realty staff only.');
        }

        return $next($request);
    }
}
