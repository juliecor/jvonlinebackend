<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The realty dashboard's first page: who the realty is and its numbers. Agents see it too.
 * A broker's projects and units are its developer's; its offers are the ones its firm sold.
 */
class OverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $realty = $user->realty;
        $developer = $realty->isDeveloper();
        $visibleOffers = fn () => Offer::visibleTo($user);

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
                'agents_pending' => $realty->agentApplications()->where('status', User::STATUS_PENDING)->count(),
                'agents_invited' => $realty->agentInvitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
                'staff' => $realty->users()->where('role', User::ROLE_REALTY)->count(),
                'projects' => $realty->inventoryProjects()->count(),
                'public_projects' => $realty->inventoryProjects()->where('is_public', true)->count(),
                'new_responses' => OfferResponse::whereIn('offer_id', $visibleOffers()->select('offers.id'))->whereNull('seen_at')->count(),
                // Buyer files nobody has approved or sent back yet (on active offers this person can see). The developer checks them, not a broker.
                'docs_to_review' => $developer
                    ? OfferDocument::where('status', 'pending')->whereIn('offer_id', $visibleOffers()->where('offers.status', 'active')->select('offers.id'))->count()
                    : 0,
                // Custom terms: the developer's admins see what waits for them; agents see what was sent back to them.
                // Accreditation forms waiting for the developer's staff (Team > Realties).
                'realties_pending' => $user->isDeveloperStaff() ? $realty->accreditations()->where('status', 'submitted')->count() : 0,
                'to_approve' => $user->isDeveloperStaff() ? $visibleOffers()->where('offers.status', 'active')->where('approval_status', 'pending')->count() : 0,
                'sent_back' => $visibleOffers()->where('offers.status', 'active')->where('approval_status', 'rejected')->where('agent_id', $user->id)->count(),
                'units' => Unit::whereIn('project_id', Project::where('realty_id', $realty->inventoryId())->select('id'))->count(),
                // Agents see their own offers; staff the whole realty's (a broker's: what its firm sold).
                'offers' => $visibleOffers()->where('offers.status', 'active')->count(),
            ],
        ]);
    }
}
