<?php

namespace App\Http\Middleware;

use App\Models\Realty;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes under /api/realty: realty staff and agents, each scoped to their own
 * realty. `realty.member` lets both in; `realty.member:staff` only the staff.
 * A second argument limits it to a kind of realty: `realty.member:staff,developer`
 * is for the developer's (Johndorf's) own admins, `realty.member:any,developer` for
 * anyone of its team. Brokers sell the developer's units but never edit them.
 * Agents still waiting on (or turned down by) their realty's staff are kept out,
 * and so is anyone who still has to replace the temporary password they were mailed.
 */
class EnsureRealtyMember
{
    public function handle(Request $request, Closure $next, string $level = 'any', ?string $kind = null): Response
    {
        $user = $request->user();
        if (! $user || ! $user->realty_id || ! in_array($user->role, [User::ROLE_REALTY, User::ROLE_AGENT], true)) {
            abort(403, 'Realty accounts only.');
        }
        if (! $user->isActive()) {
            abort(403, 'This account is not approved yet.');
        }
        if ($user->must_change_password) {
            return response()->json(['message' => 'Set a new password to continue.', 'code' => 'password_change_required'], 403);
        }

        $realty = $user->realty;
        if (! $realty || $realty->status !== Realty::STATUS_ACTIVE) {
            abort(403, 'This realty is not active.');
        }
        if ($level === 'staff' && $user->role !== User::ROLE_REALTY) {
            abort(403, 'Realty staff only.');
        }
        if ($kind === 'developer' && ! $realty->isDeveloper()) {
            abort(403, "Only the developer's team can do this.");
        }

        return $next($request);
    }
}
