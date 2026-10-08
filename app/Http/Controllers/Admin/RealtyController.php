<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Realty;
use Illuminate\Http\JsonResponse;

/** The admin's Realty section: every realty and, for each, its people, projects and offers. Realties are invited from Johndorf's Team > Realties. */
class RealtyController extends Controller
{
    public function index(): JsonResponse
    {
        $realties = Realty::with('developer:id,name,slug')
            ->withCount('users')
            ->orderByRaw("status = 'active' desc")
            ->orderBy('name')
            ->get();

        return response()->json($realties);
    }

    /** One realty in full: profile, people, projects and latest offers. */
    public function show(Realty $realty): JsonResponse
    {
        // A broker has no projects of its own; its offers are the ones it sold on its developer's units.
        $offerRelation = $realty->isBroker() ? 'brokerOffers' : 'offers';
        $realty->load(['developer:id,name,slug', 'users' => fn ($q) => $q->orderBy('role')->orderBy('name')])
            ->loadCount(['users', 'agents', 'projects', "{$offerRelation} as offers_count"]);
        $projects = $realty->projects()->withCount(['units', 'paymentPlans', 'offers'])->orderBy('name')->get();
        $offers = $realty->{$offerRelation}()->with(['project:id,name', 'unit:id,name,unit_type', 'agent:id,name'])->latest()->take(50)->get()->map(fn ($o) => [
            'id' => $o->id, 'code' => $o->code, 'status' => $o->status, 'buyer_name' => $o->buyer_name, 'price' => (float) $o->price, 'views' => $o->views,
            'created_at' => $o->created_at, 'project' => $o->project?->name, 'unit' => $o->unit?->name, 'agent' => $o->agent?->name, 'url' => Offer::url($o->code),
        ]);

        return response()->json([
            'realty' => $realty,
            'people' => $realty->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'joined_at' => $u->created_at]),
            'projects' => $projects,
            'offers' => $offers,
            'offer_views' => (int) $realty->{$offerRelation}()->sum('views'),
        ]);
    }
}
