<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `section:offers` and the like: a realty's staff member only reaches the
 * dashboard sections their admin gave them (see User::SECTIONS);
 * `section:staff` is for full admins. Runs after realty.member.
 */
class EnsureSection
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canUse($section), 403, "Your account doesn't have access to this. Ask your admin.");

        return $next($request);
    }
}
