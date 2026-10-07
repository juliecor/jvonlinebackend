<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Numbers across every realty, plus the latest things that happened — the admin dashboard. */
class StatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $recent = collect()
            ->concat(Realty::whereNotNull('registered_at')->latest('registered_at')->take(10)->get()->map(fn (Realty $r) => [
                'kind' => 'realty_registered', 'at' => $r->registered_at, 'realty' => $r->name, 'realty_id' => $r->id, 'text' => "{$r->name} registered",
            ]))
            ->concat(Realty::where('status', Realty::STATUS_INVITED)->whereNotNull('invited_at')->latest('invited_at')->take(10)->get()->map(fn (Realty $r) => [
                'kind' => 'realty_invited', 'at' => $r->invited_at, 'realty' => $r->name, 'realty_id' => $r->id, 'text' => "{$r->name} was invited",
            ]))
            ->concat(User::with('realty')->where('role', User::ROLE_AGENT)->latest()->take(10)->get()->map(fn (User $u) => [
                'kind' => 'agent_joined', 'at' => $u->created_at, 'realty' => $u->realty?->name, 'realty_id' => $u->realty_id, 'text' => "{$u->name} joined ".($u->realty?->name ?? 'a realty').' as an agent',
            ]))
            ->concat(Project::with('realty')->latest()->take(10)->get()->map(fn (Project $p) => [
                'kind' => 'project_added', 'at' => $p->created_at, 'realty' => $p->realty?->name, 'realty_id' => $p->realty_id, 'text' => ($p->realty?->name ?? 'A realty')." added the project {$p->name}",
            ]))
            ->concat(Offer::with(['realty', 'agent', 'unit'])->latest()->take(15)->get()->map(fn (Offer $o) => [
                'kind' => 'offer_created', 'at' => $o->created_at, 'realty' => $o->realty?->name, 'realty_id' => $o->realty_id, 'text' => ($o->agent?->name ?? 'Someone')." sent {$o->buyer_name} an offer for ".($o->unit?->name ?? 'a unit').' ('.($o->realty?->name ?? '').')', 'code' => $o->code,
            ]))
            ->concat(OfferResponse::with(['offer.unit', 'offer.realty'])->latest()->take(15)->get()->map(fn ($r) => [
                'kind' => 'offer_response', 'at' => $r->created_at, 'realty' => $r->offer?->realty?->name, 'realty_id' => $r->realty_id,
                'text' => "{$r->name}: ".(OfferResponse::LABELS[$r->kind] ?? $r->kind).' — '.($r->offer?->unit?->name ?? 'a unit').' ('.($r->offer?->realty?->name ?? '').')', 'code' => $r->offer?->code,
            ]))
            ->concat(OfferDocument::with(['offer.unit', 'offer.realty', 'type'])->latest()->take(15)->get()->map(fn ($d) => [
                'kind' => 'document_uploaded', 'at' => $d->created_at, 'realty' => $d->offer?->realty?->name, 'realty_id' => $d->realty_id,
                'text' => ($d->offer?->buyer_name ?? 'A buyer').' sent '.($d->type?->name ?? 'a document').' — '.($d->offer?->unit?->name ?? 'a unit').' ('.($d->offer?->realty?->name ?? '').')', 'code' => $d->offer?->code,
            ]))
            ->sortByDesc('at')
            ->take(20)
            ->values();

        return response()->json([
            'realties' => Realty::count(),
            'realties_active' => Realty::where('status', Realty::STATUS_ACTIVE)->count(),
            'realties_invited' => Realty::where('status', Realty::STATUS_INVITED)->count(),
            'realty_users' => User::where('role', User::ROLE_REALTY)->count(),
            'agents' => User::where('role', User::ROLE_AGENT)->count(),
            'projects' => Project::count(),
            'units' => Unit::count(),
            'offers_active' => Offer::where('status', 'active')->count(),
            'offers_total' => Offer::count(),
            'offer_views' => (int) Offer::sum('views'),
            'offer_responses' => OfferResponse::count(),
            'documents' => OfferDocument::count(),
            'documents_to_review' => OfferDocument::where('status', 'pending')->count(),
            'recent' => $recent,
        ]);
    }
}
