<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Realty;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Numbers for the admin dashboard — grows as realties, agents and offers do. */
class StatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'realties' => Realty::count(),
            'realties_active' => Realty::where('status', Realty::STATUS_ACTIVE)->count(),
            'realties_invited' => Realty::where('status', Realty::STATUS_INVITED)->count(),
            'realty_users' => User::where('role', User::ROLE_REALTY)->count(),
            'agents' => User::where('role', User::ROLE_AGENT)->count(),
        ]);
    }
}
