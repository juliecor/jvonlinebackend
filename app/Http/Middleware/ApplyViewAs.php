<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A super admin previewing a realty's dashboard signs in with a token named
 * "view-as:<realty id>:<role>". For that request they act as that realty's
 * admin or agent, so every existing check and scope just works. Only the
 * in-memory user changes: role and realty are never saved.
 */
class ApplyViewAs
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && str_starts_with($token->name, User::VIEW_AS_TOKEN)) {
            abort_unless($user->isSuperAdmin(), 403, 'Super admins only.');
            [$realtyId, $role] = explode(':', substr($token->name, strlen(User::VIEW_AS_TOKEN))) + [null, null];
            abort_unless(in_array($role, [User::ROLE_REALTY, User::ROLE_AGENT], true), 403, 'Unknown role.');
            $user->forceFill(['role' => $role, 'realty_id' => (int) $realtyId])
                ->syncOriginalAttributes(['role', 'realty_id'])
                ->unsetRelation('realty');
        }

        return $next($request);
    }
}
