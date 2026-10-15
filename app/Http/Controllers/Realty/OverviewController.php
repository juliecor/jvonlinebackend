<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
                // This person's open tasks, and how many are past their due date (the sidebar's Tasks count).
                'my_tasks' => Task::where('realty_id', $realty->id)->where('assignee_id', $user->id)->where('status', '!=', 'done')->count(),
                'my_tasks_overdue' => Task::where('realty_id', $realty->id)->where('assignee_id', $user->id)->where('status', '!=', 'done')->whereDate('due_on', '<', now('Asia/Manila')->toDateString())->count(),
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
            'analytics' => $this->analytics($user),
        ]);
    }

    /**
     * The statistics on the Overview: the inventory by status, six months of offers and deals, how far buyers
     * get (sent → opened → answered → reserved), each project's lots, the top agents, reservations and the
     * latest things that happened. Everything is limited to what this person may see: an agent's own offers.
     *
     * @return array<string, mixed>
     */
    private function analytics(User $user): array
    {
        $realty = $user->realty;
        $tz = 'Asia/Manila';
        $agent = $user->role === User::ROLE_AGENT;
        $offers = fn () => Offer::visibleTo($user);
        $projectIds = Project::where('realty_id', $realty->inventoryId())->where('status', 'active')->pluck('id');

        // Inventory: every unit or lot by status.
        $byStatus = Unit::whereIn('project_id', $projectIds)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $inventory = ['available' => (int) ($byStatus['available'] ?? 0), 'reserved' => (int) ($byStatus['reserved'] ?? 0), 'sold' => (int) ($byStatus['sold'] ?? 0)];

        // Six months: offers sent, and units reserved or sold through those offers, by month.
        $start = now($tz)->startOfMonth()->subMonths(5);
        $months = collect(range(0, 5))->map(fn (int $i) => $start->copy()->addMonths($i));
        $sent = $offers()->where('offers.created_at', '>=', $start->copy()->utc())->pluck('offers.created_at');
        $deals = Unit::whereIn('status', ['reserved', 'sold'])->whereNotNull('status_offer_id')->whereIn('status_offer_id', $offers()->select('offers.id'))
            ->where('status_at', '>=', $start->copy()->utc())->get(['status', 'status_at']);
        $key = fn ($at) => Carbon::parse($at)->timezone($tz)->format('Y-m');
        $trend = $months->map(fn (Carbon $m) => [
            'month' => $m->format('Y-m'),
            'label' => $m->format('M'),
            'offers' => $sent->filter(fn ($at) => $key($at) === $m->format('Y-m'))->count(),
            'reserved' => $deals->filter(fn (Unit $u) => $u->status === 'reserved' && $key($u->status_at) === $m->format('Y-m'))->count(),
            'sold' => $deals->filter(fn (Unit $u) => $u->status === 'sold' && $key($u->status_at) === $m->format('Y-m'))->count(),
        ])->values()->all();

        // How far buyers get, over every offer this person can see.
        $all = $offers()->with(['responses:id,offer_id,kind', 'unit:id,status,status_offer_id'])->get(['offers.id', 'offers.views', 'offers.reservation_status', 'offers.unit_id', 'offers.price', 'offers.status']);
        $holds = fn (Offer $o) => $o->unit && $o->unit->status_offer_id === $o->id && $o->unit->status !== 'available';
        $funnel = [
            ['step' => 'Offers sent', 'n' => $all->count()],
            ['step' => 'Opened by the buyer', 'n' => $all->filter(fn (Offer $o) => $o->views > 0)->count()],
            ['step' => 'Buyer answered', 'n' => $all->filter(fn (Offer $o) => $o->responses->isNotEmpty())->count()],
            ['step' => 'Interested', 'n' => $all->filter(fn (Offer $o) => $o->responses->contains('kind', 'interested'))->count()],
            ['step' => 'Reserved or sold', 'n' => $all->filter($holds)->count()],
        ];

        // Each project: its lots or units by status, busiest first.
        $projects = Project::whereIn('id', $projectIds)->withCount([
            'units',
            'units as available' => fn ($q) => $q->where('status', 'available'),
            'units as reserved' => fn ($q) => $q->where('status', 'reserved'),
            'units as sold' => fn ($q) => $q->where('status', 'sold'),
        ])->get()->filter(fn (Project $p) => $p->units_count > 1)
            ->sortByDesc(fn (Project $p) => [$p->reserved + $p->sold, $p->units_count])->take(8)
            ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'location' => $p->location, 'units' => (int) $p->units_count, 'available' => (int) $p->available, 'reserved' => (int) $p->reserved, 'sold' => (int) $p->sold])->values()->all();

        // The team: who sends the most offers and gets the most answers and deals (admins only).
        $agents = $agent ? [] : $offers()->whereNotNull('agent_id')->with(['agent:id,name,photo_path', 'responses:id,offer_id,kind', 'unit:id,status,status_offer_id'])->get(['offers.id', 'offers.agent_id', 'offers.unit_id'])
            ->groupBy('agent_id')->map(fn (Collection $os) => [
                'name' => $os->first()->agent?->name,
                'photo' => $os->first()->agent?->photoUrl(),
                'offers' => $os->count(),
                'interested' => $os->filter(fn (Offer $o) => $o->responses->contains('kind', 'interested'))->count(),
                'deals' => $os->filter($holds)->count(),
            ])->filter(fn (array $a) => $a['name'])->sortByDesc(fn (array $a) => [$a['deals'], $a['offers']])->take(5)->values()->all();

        // Reservations and the value of the homes held.
        $reserved = $all->filter($holds);
        $reservations = [
            'to_confirm' => $offers()->where('offers.status', 'active')->whereIn('reservation_status', ['pending', 'paid'])->where(fn ($q) => $q->where('reservation_status', 'paid')->orWhere('reservation_method', 'proof'))->count(),
            'confirmed' => $offers()->where('reservation_status', 'confirmed')->count(),
            'fees_confirmed' => (float) $offers()->where('reservation_status', 'confirmed')->sum('reservation_fee'),
            'value_held' => (float) $reserved->sum('price'),
        ];

        // The latest things that happened: offers sent, buyers' answers, reservations, units reserved or sold.
        $recent = collect()
            ->merge($offers()->with(['unit:id,name', 'agent:id,name'])->latest('offers.created_at')->limit(6)->get()->map(fn (Offer $o) => ['kind' => 'offer', 'at' => $o->created_at, 'text' => ($agent ? 'You sent' : ($o->agent?->name ?? 'Someone').' sent')." an offer to {$o->buyer_name}", 'detail' => $o->unit?->name, 'offer_id' => $o->id]))
            ->merge(OfferResponse::whereIn('offer_id', $offers()->select('offers.id'))->with('offer:id,unit_id', 'offer.unit:id,name')->latest()->limit(6)->get()->map(fn (OfferResponse $r) => ['kind' => $r->kind === 'interested' ? 'interested' : 'answer', 'at' => $r->created_at, 'text' => "{$r->name} ".($r->kind === 'interested' ? 'is interested' : ($r->kind === 'question' ? 'asked a question' : 'answered')), 'detail' => $r->offer?->unit?->name, 'offer_id' => $r->offer_id]))
            ->merge($offers()->whereNotNull('reservation_requested_at')->with('unit:id,name')->latest('reservation_requested_at')->limit(6)->get()->map(fn (Offer $o) => ['kind' => 'reservation', 'at' => $o->reservation_confirmed_at ?? $o->reservation_paid_at ?? $o->reservation_requested_at, 'text' => $o->reservation_status === 'confirmed' ? "{$o->buyer_name}'s reservation was confirmed" : ($o->reservation_status === 'paid' ? "{$o->buyer_name} paid the reservation fee" : "{$o->buyer_name} sent proof of payment"), 'detail' => $o->unit?->name, 'offer_id' => $o->id]))
            ->merge(Unit::whereIn('status', ['reserved', 'sold'])->whereNotNull('status_at')->whereIn('status_offer_id', $offers()->select('offers.id'))->with('statusOffer:id,buyer_name')->latest('status_at')->limit(6)->get()->map(fn (Unit $u) => ['kind' => $u->status, 'at' => $u->status_at, 'text' => "{$u->name} ".($u->status === 'sold' ? 'was sold' : 'was reserved')." for {$u->statusOffer?->buyer_name}", 'detail' => null, 'offer_id' => $u->status_offer_id]))
            ->filter(fn (array $e) => $e['at'])->sortByDesc('at')->take(8)->values()
            ->map(fn (array $e) => ['at' => Carbon::parse($e['at'])->toIso8601String()] + $e)->all();

        return compact('inventory', 'trend', 'funnel', 'projects', 'agents', 'reservations', 'recent');
    }
}
