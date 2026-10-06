<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\OfferResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The realty dashboard's first page: who the realty is and its numbers. Agents see it too. */
class OverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $realty = $user->realty;

        return response()->json([
            'realty' => $realty->publicArray() + [
                'email' => $realty->email,
                'contact_name' => $realty->contact_name,
                'phone' => $realty->phone,
                'address' => $realty->address,
                'about' => $realty->about,
                'registered_at' => $realty->registered_at,
            ],
            'stats' => [
                'agents' => $realty->agents()->count(),
                'agents_invited' => $realty->agentInvitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
                'staff' => $realty->users()->where('role', User::ROLE_REALTY)->count(),
                'projects' => $realty->projects()->count(),
                'public_projects' => $realty->projects()->where('is_public', true)->count(),
                'new_responses' => OfferResponse::where('realty_id', $realty->id)->whereNull('seen_at')
                    ->when($user->role === User::ROLE_AGENT, fn ($q) => $q->whereHas('offer', fn ($o) => $o->where('agent_id', $user->id)))->count(),
                'units' => $realty->projects()->withCount('units')->get()->sum('units_count'),
                // Agents see their own offers; staff the whole realty's.
                'offers' => $realty->offers()->where('status', 'active')->when($user->role === User::ROLE_AGENT, fn ($q) => $q->where('agent_id', $user->id))->count(),
            ],
        ]);
    }
}
