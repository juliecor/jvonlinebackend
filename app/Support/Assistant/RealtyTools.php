<?php

namespace App\Support\Assistant;

use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\PaymentPlan;
use App\Models\Project;
use App\Models\Realty;
use App\Models\RequirementType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the dashboard's AI assistant may look up, as OpenAI function tools.
 *
 * Read-only, always inside the signed-in person's realty, and agents only see
 * their own offers. Buyers appear by name and progress only: never a phone,
 * email, address, ID, TIN/SSS, income or a document, and phone numbers or
 * emails a buyer typed into a message are blanked out.
 */
class RealtyTools
{
    private const MAX_ROWS = 25;

    /** Units the model asked to show as cards under its answer (ids, in order). */
    private array $shown = [];

    public function __construct(private readonly User $user) {}

    /** @return array<int, int> */
    public function shownUnitIds(): array
    {
        return $this->shown;
    }

    /**
     * The tool list for OpenAI's chat completions API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $tool = fn (string $name, string $description, array $properties = []) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false],
            ],
        ];
        $text = fn (string $d) => ['type' => 'string', 'description' => $d];
        $number = fn (string $d) => ['type' => 'number', 'description' => $d];

        return [
            $tool('overview', 'The realty at a glance today: projects, units by status, active offers, new buyer responses, files to review, approvals and agents.'),
            $tool('list_projects', 'Projects with location, stage, whether they are open for offers, unit counts by status and price range.', [
                'query' => $text('Part of a project name or place, e.g. "Plumera" or "Cebu".'),
                'include_archived' => ['type' => 'boolean', 'description' => 'Also list archived projects.'],
            ]),
            $tool('project_details', 'One project in full: description, amenities, fee notes, payment plans with their milestones, and units grouped by type with counts and price ranges.', [
                'project' => $text('The project name, or part of it.'),
            ]),
            $tool('search_units', 'Find units: by project, status, type, price range or unit name. Returns how many match and up to 25 of them, each with an id for show_units.', [
                'project' => $text('Project name, or part of it.'),
                'status' => ['type' => 'string', 'enum' => ['available', 'reserved', 'sold']],
                'unit_type' => $text('e.g. "Studio Unit", "1BR Unit", "Two-Storey Townhouse".'),
                'min_price' => $number('Lowest price in pesos.'),
                'max_price' => $number('Highest price in pesos.'),
                'query' => $text('Part of a unit name, e.g. "Bldg J" or "Block 24".'),
                'sort' => ['type' => 'string', 'enum' => ['price_low', 'price_high', 'name']],
            ]),
            $tool('show_units', 'Show units as cards under your answer: photo, project, type, floor area, price, status, and a "Make offer" button on available ones. Use it whenever you present specific units someone may want to look at or offer (show me, which units, cheapest, best for a family, compare these), with ids from search_units. Up to 6, in the order to show. Not for counts or statistics.', [
                'unit_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Unit ids from search_units, in order.'],
            ]),
            $tool('list_offers', "Sales offers sent to buyers, newest first, with each one's progress. Filter by status, project, agent, buyer, the buyer's answer, or what still needs doing.", [
                'status' => ['type' => 'string', 'enum' => ['active', 'void', 'all']],
                'project' => $text('Project name, or part of it.'),
                'agent' => $text("Agent's name, or part of it."),
                'buyer' => $text("Buyer's name, or part of it."),
                'response' => ['type' => 'string', 'enum' => ['interested', 'question', 'not_interested', 'none']],
                'needs' => ['type' => 'string', 'enum' => ['documents', 'review', 'approval'], 'description' => 'documents: buyer still missing requirements; review: buyer files waiting to be checked; approval: custom terms waiting.'],
            ]),
            $tool('offer_details', "One offer in full: which requirements (documents and the buyer form) are missing, waiting for review or approved; the buyer's payment schedule; the buyer's answers; when it was sent, opened and reminded; and the buyer's own link, for messages.", [
                'offer' => $text("The offer code (e.g. 3XT3WEF4P7) or the buyer's name."),
            ]),
            $tool('buyer_responses', "Buyers' answers to offers (interested, a question, not interested), newest first.", [
                'days' => ['type' => 'integer', 'description' => 'Only the last N days.'],
                'kind' => ['type' => 'string', 'enum' => ['interested', 'question', 'not_interested']],
            ]),
            $tool('list_agents', "The realty's agents: how many, who is active, who applied and waits for approval, unused invite links, and each agent's offers and buyer responses. Realty admins only."),
            $tool('compute_payments', "Work out a buyer's payments in pesos for a unit (or a price): with one of the project's payment plans, or with custom terms (e.g. a ₱20,000 reservation fee, 20% down over 24 months, the rest on turnover). Same math as the offers, so the amounts match what the buyer would see. Use it for every how-much, monthly, down payment or what-if question; never do payment math yourself.", [
                'unit' => $text('The unit name, or part of it, e.g. "Block 24 · Lot 27".'),
                'project' => $text('The project: needed for its plans, or when a unit name is in several projects.'),
                'price' => $number('A price in pesos, when there is no unit.'),
                'plan' => $text("One of the project's payment plans, by name or part of it."),
                'terms' => [
                    'type' => 'array',
                    'description' => 'Custom terms instead of a plan, in order. Together they must make 100% of the price: give the last one rest: true for the remaining balance.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'percent' => ['type' => 'number', 'description' => 'Share of the price.'],
                            'amount' => ['type' => 'number', 'description' => 'A fixed amount in pesos, instead of a percent.'],
                            'rest' => ['type' => 'boolean', 'description' => 'This payment is whatever is left.'],
                            'due' => ['type' => 'string', 'enum' => ['on_purchase', 'days_after_purchase', 'on_turnover']],
                            'days' => ['type' => 'integer', 'description' => 'With days_after_purchase.'],
                            'months' => ['type' => 'integer', 'description' => 'Paid monthly over this many months, from the due date.'],
                        ],
                        'required' => ['label', 'due'],
                    ],
                ],
                'purchase_date' => $text('YYYY-MM-DD. Today if left out.'),
            ]),
            $tool('attention_today', 'Everything that needs the person now, in one lookup: new buyer answers nobody opened yet, buyer files to review, custom terms to approve (or sent back), interested buyers still missing requirements, buyers who opened their offer often but never answered, links not opened 3+ days after sending, agent applications, and units reserved or sold in the last 7 days. Use it for "what needs my attention", "what should I do today", "any updates", "good morning", "summary of today".'),
        ];
    }

    /**
     * Run one tool. Unknown tools and bad arguments come back as an error the model can read.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $name, array $args): array
    {
        return match ($name) {
            'overview' => $this->overview(),
            'list_projects' => $this->listProjects($args),
            'project_details' => $this->projectDetails($args),
            'search_units' => $this->searchUnits($args),
            'show_units' => $this->showUnits($args),
            'list_offers' => $this->listOffers($args),
            'offer_details' => $this->offerDetails($args),
            'buyer_responses' => $this->buyerResponses($args),
            'list_agents' => $this->listAgents(),
            'compute_payments' => $this->computePayments($args),
            'attention_today' => $this->attention(),
            default => ['error' => "There is no tool called {$name}."],
        };
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $realty = $this->realty();
        $units = Unit::where('realty_id', $realty->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $offers = $this->offers();

        return [
            'realty' => $realty->name,
            'today' => now('Asia/Manila')->toDateString(),
            'you' => ['name' => $this->user->name, 'role' => $this->isAgent() ? 'agent (sees only their own offers)' : 'realty admin'],
            'projects' => $realty->projects()->count(),
            'projects_open_for_offers' => $realty->projects()->where('status', 'active')->count(),
            'units' => ['total' => (int) $units->sum(), 'available' => (int) ($units['available'] ?? 0), 'reserved' => (int) ($units['reserved'] ?? 0), 'sold' => (int) ($units['sold'] ?? 0)],
            'active_offers' => (clone $offers)->where('status', 'active')->count(),
            'offers_sent_this_month' => (clone $offers)->where('created_at', '>=', now('Asia/Manila')->startOfMonth()->utc())->count(),
            'offers_sent_last_7_days' => (clone $offers)->where('created_at', '>=', now()->subDays(7))->count(),
            'reserved_and_sold_units' => Unit::where('realty_id', $realty->id)->whereIn('status', ['reserved', 'sold'])->with(['project:id,name', 'statusOffer:id,buyer_name,agent_id'])->latest('status_at')->limit(12)->get()
                ->map(fn (Unit $u) => array_filter([
                    'unit' => $u->name,
                    'project' => $u->project?->name,
                    'status' => $u->status,
                    'buyer' => $u->statusOffer && $this->canSeeOfferOf($u->statusOffer->agent_id) ? $u->statusOffer->buyer_name : null,
                    'since' => $u->status_at?->format('M j, Y'),
                ]))->all(),
            'new_buyer_responses' => OfferResponse::whereNull('seen_at')->whereIn('offer_id', (clone $offers)->select('id'))->count(),
            'buyer_files_to_review' => OfferDocument::where('status', 'pending')->whereIn('offer_id', (clone $offers)->where('status', 'active')->select('id'))->count(),
            'custom_terms_waiting_for_approval' => (clone $offers)->where('status', 'active')->where('approval_status', 'pending')->count(),
            'agents' => $this->isAgent() ? null : $realty->agents()->count(),
            'agent_applications_waiting' => $this->isAgent() ? null : $realty->agentApplications()->where('status', User::STATUS_PENDING)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function listProjects(array $args): array
    {
        $projects = $this->realty()->projects()
            ->when(! ($args['include_archived'] ?? false), fn ($q) => $q->where('status', 'active'))
            ->when($this->text($args, 'query'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('location', 'like', "%{$s}%")->orWhere('region', 'like', "%{$s}%")))
            ->withCount([
                'units',
                'units as available' => fn ($q) => $q->where('status', 'available'),
                'units as reserved' => fn ($q) => $q->where('status', 'reserved'),
                'units as sold' => fn ($q) => $q->where('status', 'sold'),
                'paymentPlans',
            ])
            ->withMin('units', 'price')
            ->withMax('units', 'price')
            ->orderBy('name')
            ->get();

        return [
            'count' => $projects->count(),
            'projects' => $projects->map(fn (Project $p) => [
                'name' => $p->name,
                'location' => $p->location,
                'region' => $p->region,
                'stage' => $p->stage,
                'open_for_offers' => $p->status === 'active',
                'on_website' => (bool) $p->is_public,
                'units' => ['total' => $p->units_count, 'available' => $p->available, 'reserved' => $p->reserved, 'sold' => $p->sold],
                'price_range' => $this->range($p->units_min_price, $p->units_max_price),
                'payment_plans' => $p->payment_plans_count,
                'dashboard_url' => $this->url("projects/{$p->id}"),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function projectDetails(array $args): array
    {
        $project = $this->findProject($this->text($args, 'project'));
        if (! $project instanceof Project) {
            return $project;
        }
        $units = $project->units()->get(['name', 'unit_type', 'status', 'price', 'area_sqm']);
        // Plans are in percent: work them out on one real unit (the cheapest available with a price) so answers can say pesos.
        $example = $units->where('status', 'available')->whereNotNull('price')->sortBy('price')->first() ?? $units->whereNotNull('price')->sortBy('price')->first();

        return [
            'name' => $project->name,
            'location' => $project->location,
            'region' => $project->region,
            'stage' => $project->stage,
            'open_for_offers' => $project->status === 'active',
            'turnover' => $project->completion_date?->format('M j, Y') ?? 'not announced yet',
            'description' => $project->description,
            'amenities' => $project->amenities ?? [],
            'fee_notes' => $project->fee_notes,
            'payment_plans' => $project->paymentPlans()->orderBy('id')->get()->map(fn (PaymentPlan $plan) => [
                'name' => $plan->name,
                'milestones' => collect($plan->milestones)->map(fn (array $m) => [
                    'label' => $m['label'],
                    'percent' => round((float) $m['percent'], 2),
                    'due' => $m['days'] === null ? 'on completion' : ($m['days'] == 0 ? 'on the purchase date' : "{$m['days']} days after purchase"),
                    'monthly_payments' => $m['months'] ?? null,
                ])->all(),
                'example_in_pesos' => $example ? [
                    'unit' => $example->name,
                    'unit_price' => (float) $example->price,
                    'payments' => collect($plan->milestones)->map(fn (array $m) => array_filter([
                        'label' => $m['label'],
                        'amount' => round((float) $example->price * (float) $m['percent'] / 100, 2),
                        'each_month' => ($m['months'] ?? null) ? round((float) $example->price * (float) $m['percent'] / 100 / (int) $m['months'], 2) : null,
                    ], fn ($v) => $v !== null))->all(),
                ] : null,
            ])->all(),
            'units_by_type' => $units->groupBy(fn (Unit $u) => $u->unit_type ?: 'Other')->map(fn (Collection $of, string $type) => [
                'type' => $type,
                'total' => $of->count(),
                'available' => $of->where('status', 'available')->count(),
                'reserved' => $of->where('status', 'reserved')->count(),
                'sold' => $of->where('status', 'sold')->count(),
                'price_range' => $this->range($of->min('price'), $of->max('price')),
                'floor_area_sqm' => $this->range($of->min('area_sqm'), $of->max('area_sqm'), false),
            ])->values()->all(),
            'website' => $project->is_public && $project->slug ? "/projects/{$project->slug}" : null,
            'dashboard_url' => $this->url("projects/{$project->id}"),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function searchUnits(array $args): array
    {
        $project = null;
        if ($name = $this->text($args, 'project')) {
            $project = $this->findProject($name);
            if (! $project instanceof Project) {
                return $project;
            }
        }
        $query = Unit::where('realty_id', $this->realty()->id)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->when(in_array($args['status'] ?? null, Unit::STATUSES, true), fn ($q) => $q->where('status', $args['status']))
            ->when($this->text($args, 'unit_type'), fn ($q, $t) => $q->where('unit_type', 'like', "%{$t}%"))
            ->when(isset($args['min_price']), fn ($q) => $q->where('price', '>=', (float) $args['min_price']))
            ->when(isset($args['max_price']), fn ($q) => $q->where('price', '<=', (float) $args['max_price']))
            ->when($this->text($args, 'query'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"));
        $total = (clone $query)->count();
        $sort = $args['sort'] ?? 'price_low';
        $units = $query->with(['project:id,name', 'statusOffer:id,buyer_name,agent_id'])
            ->when($sort === 'name', fn ($q) => $q->orderBy('name'), fn ($q) => $q->orderByRaw('price is null')->orderBy('price', $sort === 'price_high' ? 'desc' : 'asc'))
            ->limit(self::MAX_ROWS)
            ->get();

        return [
            'total_matching' => $total,
            'showing' => $units->count(),
            'units' => $units->map(fn (Unit $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'project' => $u->project?->name,
                'type' => $u->unit_type,
                'floor' => $u->floor,
                'floor_area_sqm' => $u->area_sqm !== null ? (float) $u->area_sqm : null,
                'price' => $u->price !== null ? (float) $u->price : null,
                'status' => $u->status,
                'held_by_buyer' => $u->status !== 'available' && $u->statusOffer && $this->canSeeOfferOf($u->statusOffer->agent_id) ? $u->statusOffer->buyer_name : null,
                'notes_for_buyers' => $u->buyer_notes,
                'dashboard_url' => $this->url("projects/{$u->project_id}#units"),
            ])->all(),
        ];
    }

    /**
     * Put units under the answer as cards. Only this realty's units; the
     * cards themselves are drawn from the live units by UnitCards.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function showUnits(array $args): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($args['unit_ids'] ?? [])))));
        $names = Unit::where('realty_id', $this->realty()->id)->whereIn('id', $ids)->pluck('name', 'id');
        $found = array_values(array_filter($ids, fn (int $id) => $names->has($id)));
        if ($found === []) {
            return ['error' => 'None of those ids are units of this realty. Use the ids from search_units.'];
        }
        $this->shown = array_slice(array_values(array_unique([...$this->shown, ...$found])), 0, UnitCards::MAX);

        return [
            'shown_as_cards' => array_values(array_filter(array_map(fn (int $id) => $names[$id] ?? null, $this->shown))),
            'note' => "The cards show each unit's photo, price and status with a Make offer button. Introduce them in a sentence and give the highlights; don't repeat them in a table.",
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function listOffers(array $args): array
    {
        $status = $args['status'] ?? 'active';
        $query = $this->offers()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status === 'void' ? 'void' : 'active'))
            ->when($this->text($args, 'project'), fn ($q, $s) => $q->whereHas('project', fn ($p) => $p->where('name', 'like', "%{$s}%")))
            ->when($this->text($args, 'agent'), fn ($q, $s) => $q->whereHas('agent', fn ($a) => $a->where('name', 'like', "%{$s}%")))
            ->when($this->text($args, 'buyer'), fn ($q, $s) => $q->where('buyer_name', 'like', "%{$s}%"));
        $response = $args['response'] ?? null;
        if ($response === 'none') {
            $query->doesntHave('responses');
        } elseif (in_array($response, OfferResponse::KINDS, true)) {
            $query->whereHas('responses', fn ($r) => $r->where('kind', $response));
        }
        if (($args['needs'] ?? null) === 'approval') {
            $query->where('approval_status', 'pending');
        } elseif (($args['needs'] ?? null) === 'review') {
            $query->whereHas('documents', fn ($d) => $d->where('status', 'pending'));
        }

        $types = RequirementType::where('realty_id', $this->realty()->id)->orderBy('sort')->get();
        $offers = $query->with(['project:id,name', 'unit:id,name,unit_type,status,status_offer_id', 'agent:id,name', 'responses', 'documents'])->latest()->get();
        if (($args['needs'] ?? null) === 'documents') {
            $offers = $offers->filter(function (Offer $o) use ($types) {
                $r = $o->requirementSummary($types);

                return $r['missing'] > 0 || ! $r['details'];
            })->values();
        }

        return [
            'total_matching' => $offers->count(),
            'showing' => min($offers->count(), self::MAX_ROWS),
            'offers' => $offers->take(self::MAX_ROWS)->map(fn (Offer $o) => $this->offerRow($o, $types))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function offerDetails(array $args): array
    {
        $needle = $this->text($args, 'offer');
        if (! $needle) {
            return ['error' => 'Say which offer: its code or the buyer\'s name.'];
        }
        $matches = $this->offers()->where(fn ($q) => $q->where('code', strtoupper($needle))->orWhere('buyer_name', 'like', "%{$needle}%"))->latest()->limit(6)->get();
        if ($matches->isEmpty()) {
            return ['error' => "No offer found for \"{$needle}\"".($this->isAgent() ? ' among your offers.' : '.')];
        }
        if ($matches->count() > 1 && ! $matches->contains('code', strtoupper($needle))) {
            return ['several_match' => $matches->map(fn (Offer $o) => ['code' => $o->code, 'buyer' => $o->buyer_name, 'sent' => $o->created_at->toDateString()])->all()];
        }
        $offer = $matches->firstWhere('code', strtoupper($needle)) ?? $matches->first();
        $offer->load(['project:id,name', 'unit', 'agent:id,name', 'responses', 'documents']);

        return $this->offerRow($offer) + [
            'purchase_date' => $offer->purchase_date?->toDateString(),
            'payment_schedule' => collect($offer->schedule)->map(fn (array $m) => [
                'label' => $m['label'],
                'amount' => (float) $m['amount'],
                'due' => ($m['months'] ?? null) && ($m['end_date'] ?? null) ? "{$m['months']} monthly payments of ".number_format((float) ($m['monthly'] ?? 0), 2)." from {$m['date']} to {$m['end_date']}" : ($m['date'] ?? 'on completion'),
            ])->all(),
            'requirements' => collect($offer->requirementList())->map(fn (array $r) => ['name' => $r['name'], 'needed' => $r['needed'], 'state' => $r['state']])->all(),
            'buyer_form_sent' => $offer->details_submitted_at?->toDateString(),
            'buyer_answers' => $offer->responses->sortByDesc('created_at')->values()->map(fn (OfferResponse $r) => [
                'answer' => OfferResponse::LABELS[$r->kind] ?? $r->kind,
                'on' => $r->created_at->toDateString(),
                'message' => $this->blankContacts($r->message),
            ])->all(),
            'emailed_to_buyer' => $offer->offer_emailed_at?->toDateString(),
            'last_reminder' => $offer->last_reminded_at?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function buyerResponses(array $args): array
    {
        $responses = OfferResponse::whereIn('offer_id', $this->offers()->select('id'))
            ->when(isset($args['days']), fn ($q) => $q->where('created_at', '>=', now()->subDays(max(1, (int) $args['days']))))
            ->when(in_array($args['kind'] ?? null, OfferResponse::KINDS, true), fn ($q) => $q->where('kind', $args['kind']))
            ->with(['offer:id,code,buyer_name,project_id,unit_id,agent_id', 'offer.project:id,name', 'offer.unit:id,name', 'offer.agent:id,name'])
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get();

        return [
            'count' => $responses->count(),
            'responses' => $responses->map(fn (OfferResponse $r) => [
                'buyer' => $r->name,
                'answer' => OfferResponse::LABELS[$r->kind] ?? $r->kind,
                'on' => $r->created_at->toDateString(),
                'new' => $r->seen_at === null,
                'message' => $this->blankContacts($r->message),
                'offer' => $r->offer?->code,
                'project' => $r->offer?->project?->name,
                'unit' => $r->offer?->unit?->name,
                'agent' => $r->offer?->agent?->name,
                'dashboard_url' => $r->offer ? $this->url("offers/{$r->offer->id}#responses") : null,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function listAgents(): array
    {
        if ($this->isAgent()) {
            return ['error' => "Only the realty's admins can see the team."];
        }
        $realty = $this->realty();
        $agents = User::where('realty_id', $realty->id)->where('role', User::ROLE_AGENT)
            ->withCount(['offers as active_offers' => fn ($q) => $q->where('status', 'active')])
            ->withMax('offers', 'created_at')
            ->orderBy('name')
            ->get();
        $responses = OfferResponse::where('offer_responses.realty_id', $realty->id)->join('offers', 'offers.id', '=', 'offer_responses.offer_id')
            ->selectRaw('offers.agent_id, count(*) as n')->groupBy('offers.agent_id')->pluck('n', 'offers.agent_id');

        return [
            'active_agents' => $agents->where('status', User::STATUS_ACTIVE)->count(),
            'applications_waiting_for_approval' => $agents->where('status', User::STATUS_PENDING)->count(),
            'invite_links_sent_but_not_used_yet' => $realty->agentInvitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            'agents' => $agents->map(fn (User $a) => [
                'name' => $a->name,
                'status' => $a->status === User::STATUS_PENDING ? 'waiting for approval' : $a->status,
                'joined' => $a->created_at?->toDateString(),
                'active_offers' => $a->active_offers,
                'buyer_responses' => (int) ($responses[$a->id] ?? 0),
                'last_offer' => $a->offers_max_created_at ? substr((string) $a->offers_max_created_at, 0, 10) : null,
            ])->all(),
            'dashboard_url' => $this->url('agents'),
        ];
    }

    /**
     * One offer's progress, as the lists show it.
     *
     * @param  Collection<int, RequirementType>|null  $types
     * @return array<string, mixed>
     */
    private function offerRow(Offer $o, ?Collection $types = null): array
    {
        $latest = $o->responses->sortByDesc('created_at')->first();
        $req = $o->requirementSummary($types);
        $missing = $this->stillMissing($o, $types);

        return [
            'code' => $o->code,
            'buyer' => $o->buyer_name,
            'status' => $o->status,
            'project' => $o->project?->name,
            'unit' => $o->unit?->name,
            'price' => (float) $o->price,
            'agent' => $o->agent?->name,
            'sent' => $o->created_at->toDateString(),
            'opened' => $o->views ? "{$o->views} times, last on ".$o->last_viewed_at?->toDateString() : 'not opened yet',
            'latest_answer' => $latest ? (OfferResponse::LABELS[$latest->kind] ?? $latest->kind).' on '.$latest->created_at->toDateString() : 'no answer yet',
            'requirements' => $req['required'] ? ($req['submitted'] + ($req['details'] ? 1 : 0)).' of '.($req['required'] + 1).' in'.($req['to_review'] ? ", {$req['to_review']} to review" : '') : 'none asked',
            'still_missing' => $missing,
            'custom_terms' => $o->approval_status,
            'unit_status' => $o->unit && $o->unit->status_offer_id === $o->id ? $o->unit->status : null,
            // For messages to the buyer. A login's username and password are never given out here.
            'buyer_link' => match (true) {
                $o->status !== 'active' => 'none: the offer is void',
                $o->awaitingApproval() => 'not usable yet: the custom terms have to be approved first',
                default => Offer::url($o->code),
            },
            'buyer_has_to_sign_in' => $o->isLocked(),
            'dashboard_url' => $this->url("offers/{$o->id}"),
        ];
    }

    /**
     * A buyer's payments for a unit or a price, with a project plan or custom
     * terms, worked out by the code that builds offers' schedules.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function computePayments(array $args): array
    {
        $project = null;
        if ($name = $this->text($args, 'project')) {
            $project = $this->findProject($name);
            if (! $project instanceof Project) {
                return $project;
            }
        }
        $unit = null;
        if ($needle = $this->text($args, 'unit')) {
            $units = Unit::where('realty_id', $this->realty()->id)->when($project, fn ($q) => $q->where('project_id', $project->id))
                ->where('name', 'like', "%{$needle}%")->with('project')->limit(8)->get();
            $unit = $units->first(fn (Unit $u) => strcasecmp($u->name, $needle) === 0) ?? ($units->count() === 1 ? $units->first() : null);
            if (! $unit) {
                return $units->isEmpty()
                    ? ['error' => "No unit called \"{$needle}\". Find it with search_units first."]
                    : ['several_match' => $units->map(fn (Unit $u) => "{$u->name} ({$u->project?->name})")->all()];
            }
            $project ??= $unit->project;
        }
        $price = $unit ? ($unit->price !== null ? (float) $unit->price : null) : (isset($args['price']) ? (float) $args['price'] : null);
        if (! $price || $price <= 0) {
            return ['error' => $unit ? "{$unit->name} has no price yet." : 'Say which unit, or give a price.'];
        }

        $plans = $project?->paymentPlans()->orderBy('id')->get() ?? collect();
        if ($wanted = $this->text($args, 'plan')) {
            $plan = $plans->first(fn (PaymentPlan $p) => strcasecmp($p->name, $wanted) === 0) ?? $plans->first(fn (PaymentPlan $p) => stripos($p->name, $wanted) !== false);
            if (! $plan) {
                return ['error' => $project ? "{$project->name} has no plan called \"{$wanted}\". Its plans: ".($plans->pluck('name')->implode(', ') ?: 'none').'.' : 'Say which project the plan belongs to.'];
            }
            [$milestones, $terms] = [$plan->milestones, $plan->name];
        } elseif (is_array($args['terms'] ?? null) && $args['terms'] !== []) {
            $milestones = $this->customTerms($args['terms'], $price);
            if (is_string($milestones)) {
                return ['error' => $milestones];
            }
            $terms = 'custom terms';
        } else {
            return ['error' => 'Say which plan'.($plans->isNotEmpty() ? ' ('.$plans->pluck('name')->implode(', ').')' : '').', or give custom terms.'];
        }

        $date = $this->text($args, 'purchase_date');
        $purchase = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::parse($date, 'Asia/Manila') : now('Asia/Manila')->startOfDay();
        $schedule = Offer::buildSchedule($price, $milestones, $purchase, $project?->completion_date);
        $day = fn (?string $d) => $d ? Carbon::parse($d)->format('M j, Y') : null;

        return [
            'unit' => $unit?->name,
            'project' => $project?->name,
            'price' => $price,
            'terms' => $terms,
            'purchase_date' => $purchase->format('M j, Y'),
            'payments' => collect($schedule)->map(fn (array $r) => array_filter([
                'label' => $r['label'],
                'percent' => round((float) $r['percent'], 2),
                'amount' => $r['amount'],
                'due' => $day($r['date']) ?? 'on turnover (no date announced yet)',
                'monthly' => isset($r['months']) ? [
                    'payments' => $r['months'],
                    'each' => $r['monthly'],
                    'from' => $day($r['date']),
                    'to' => $day($r['end_date']),
                    'last_payment' => end($r['installments'])['amount'],
                ] : null,
            ], fn ($v) => $v !== null))->all(),
            'total' => round(array_sum(array_column($schedule, 'amount')), 2),
            'note' => 'Worked out like the offers: the last payment of each part takes the centavo rounding.',
        ];
    }

    /**
     * The model's custom terms as milestones (share of the price, days after
     * purchase or null for turnover, months), or what's wrong with them.
     *
     * @param  array<int, mixed>  $terms
     * @return array<int, array{label: string, percent: float, days: int|null, months: int|null}>|string
     */
    private function customTerms(array $terms, float $price): array|string
    {
        if (count($terms) > 24) {
            return 'At most 24 payments.';
        }
        $rows = [];
        $rest = null;
        foreach (array_values($terms) as $i => $t) {
            if (! is_array($t)) {
                return 'Each payment needs a label and when it is due.';
            }
            $label = trim((string) ($t['label'] ?? '')) ?: 'Payment';
            $percent = match (true) {
                ! empty($t['rest']) => null,
                isset($t['amount']) && is_numeric($t['amount']) => (float) $t['amount'] / $price * 100,
                isset($t['percent']) && is_numeric($t['percent']) => (float) $t['percent'],
                default => false,
            };
            if ($percent === false || ($percent !== null && $percent <= 0)) {
                return "Give \"{$label}\" a percent or an amount above zero, or make it the rest.";
            }
            if ($percent === null) {
                if ($rest !== null) {
                    return 'Only one payment can be the rest.';
                }
                $rest = $i;
            }
            $rows[] = [
                'label' => $label,
                'percent' => $percent ?? 0.0,
                'days' => match ($t['due'] ?? 'on_purchase') {
                    'on_turnover' => null,
                    'days_after_purchase' => max(0, min(36500, (int) ($t['days'] ?? 0))),
                    default => 0,
                },
                'months' => isset($t['months']) && (int) $t['months'] >= 2 ? min(120, (int) $t['months']) : null,
            ];
        }
        $sum = array_sum(array_column($rows, 'percent'));
        if ($rest !== null) {
            $rows[$rest]['percent'] = 100 - $sum;
            if ($rows[$rest]['percent'] <= 0.001) {
                return 'The other payments already make 100% or more, so nothing is left for the rest.';
            }
        } elseif (abs($sum - 100) > 0.01) {
            return 'The payments make '.round($sum, 2).'% of the price; they need to make 100%. Give the last one rest: true for the remaining balance.';
        }

        return $rows;
    }

    /**
     * The dashboard page a question is asked from (the "Ask" button on every
     * page), in words for the model, or null. Only what this person may see:
     * another agent's offer gives no context at all.
     */
    public function describePage(?string $page): ?string
    {
        if (! $page || ! preg_match('#^/[\w-]+/dashboard(?:/([^?\#]*))?#', $page, $m)) {
            return null;
        }
        $path = trim($m[1] ?? '', '/');
        if (preg_match('#^projects/(\d+)$#', $path, $p)) {
            $project = Project::where('realty_id', $this->realty()->id)->find((int) $p[1]);

            return $project ? "the page of the project {$project->name}".($project->location ? " ({$project->location})" : '') : null;
        }
        if (preg_match('#^offers/(\d+)$#', $path, $o)) {
            $offer = $this->offers()->with(['project:id,name', 'unit:id,name'])->find((int) $o[1]);

            return $offer ? "the offer for {$offer->buyer_name} (code {$offer->code}: {$offer->project?->name}, {$offer->unit?->name})" : null;
        }

        return match ($path) {
            '' => 'the dashboard overview',
            'projects' => 'the list of projects',
            'offers' => 'the list of sales offers',
            'approvals' => 'the approvals page (custom terms waiting for an admin)',
            'agents' => 'the agents page',
            'requirements' => 'the settings for what buyers have to send',
            default => null,
        };
    }

    /**
     * What the buyer still has to send, by name: the buyer form first, then
     * each required document not in yet (or sent back).
     *
     * @param  Collection<int, RequirementType>|null  $types
     * @return array<int, string>
     */
    private function stillMissing(Offer $o, ?Collection $types = null): array
    {
        return collect($o->requirementList(false, $types))
            ->filter(fn (array $r) => $r['needed'] === 'required' && in_array($r['state'], ['missing', 'rejected'], true))
            ->map(fn (array $r) => $r['name'].($r['state'] === 'rejected' ? ' (sent back, needs a new file)' : ''))
            ->when(! $o->details_submitted_at, fn (Collection $c) => $c->prepend('Buyer information form'))
            ->values()->all();
    }

    /**
     * What needs this person now, for the "today" briefing: things only they
     * can do, buyers to follow up, and the week's reservations and sales.
     * Agents get their own offers' only. total_things_to_do counts what needs
     * doing (the good news isn't a to-do).
     *
     * @return array<string, mixed>
     */
    public function attention(): array
    {
        $agent = $this->isAgent();
        $active = $this->offers()->where('status', 'active');
        $types = RequirementType::where('realty_id', $this->realty()->id)->orderBy('sort')->orderBy('id')->get();
        $day = fn ($at) => $at?->timezone('Asia/Manila')->format('M j, Y');
        $list = fn (Collection $items) => ['count' => $items->count(), 'items' => $items->take(8)->values()->all()];

        $answers = OfferResponse::whereNull('seen_at')->whereIn('offer_id', (clone $active)->select('id'))
            ->with(['offer:id,project_id,unit_id,agent_id', 'offer.project:id,name', 'offer.unit:id,name', 'offer.agent:id,name'])
            ->latest()->get()
            ->map(fn (OfferResponse $r) => array_filter([
                'buyer' => $r->name,
                'answer' => OfferResponse::LABELS[$r->kind] ?? $r->kind,
                'message' => $r->message ? $this->blankContacts(Str::limit($r->message, 200)) : null,
                'when' => $day($r->created_at),
                'project' => $r->offer?->project?->name,
                'unit' => $r->offer?->unit?->name,
                'agent' => $agent ? null : $r->offer?->agent?->name,
                'dashboard_url' => $this->url("offers/{$r->offer_id}#responses"),
            ]));

        $files = OfferDocument::where('status', 'pending')->whereIn('offer_id', (clone $active)->select('id'))
            ->with(['offer:id,buyer_name', 'type:id,name'])->oldest()->get()->groupBy('offer_id')
            ->map(fn (Collection $docs) => [
                'buyer' => $docs->first()->offer?->buyer_name,
                'files' => $docs->count(),
                'for' => $docs->map(fn (OfferDocument $d) => $d->type?->name ?? 'Other file')->unique()->values()->all(),
                'waiting_since' => $day($docs->first()->created_at),
                'dashboard_url' => $this->url("offers/{$docs->first()->offer_id}#requirements"),
            ])->values();

        $terms = fn (string $state) => (clone $active)->where('approval_status', $state)->with('agent:id,name')->oldest()->get()
            ->map(fn (Offer $o) => array_filter([
                'buyer' => $o->buyer_name,
                'agent' => $agent ? null : $o->agent?->name,
                'reason' => $o->approval_reason,
                'admin_note' => $state === 'rejected' ? $o->approval_note : null,
                'since' => $day($o->created_at),
                'dashboard_url' => $this->url("offers/{$o->id}"),
            ]));

        // Offers the buyer can open (no custom terms waiting).
        $live = (clone $active)->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', 'approved'))
            ->with(['responses', 'documents', 'unit:id,name,status,status_offer_id', 'agent:id,name'])->latest()->get();
        $row = fn (Offer $o, array $extra) => array_filter([
            'buyer' => $o->buyer_name,
            ...$extra,
            'agent' => $agent ? null : $o->agent?->name,
            'dashboard_url' => $this->url("offers/{$o->id}"),
        ], fn ($v) => $v !== null);

        $missing = $live->map(function (Offer $o) use ($types, $row) {
            $holds = $o->unit && $o->unit->status_offer_id === $o->id && $o->unit->status !== 'available';
            $interested = $o->responses->sortByDesc('created_at')->first()?->kind === 'interested';
            $names = ($holds || $interested) ? $this->stillMissing($o, $types) : [];

            return $names ? $row($o, ['why' => $holds ? "{$o->unit->status} {$o->unit->name}" : 'said they are interested', 'still_missing' => $names]) : null;
        })->filter();

        $quiet = $live->filter(fn (Offer $o) => $o->views >= 3 && $o->responses->isEmpty() && $o->last_viewed_at?->gt(now()->subDays(14)))
            ->sortByDesc('views')
            ->map(fn (Offer $o) => $row($o, ['opened' => "{$o->views} times", 'last_opened' => $day($o->last_viewed_at)]));

        $unopened = $live->filter(fn (Offer $o) => ! $o->views && $o->created_at->lte(now()->subDays(3)))
            ->map(fn (Offer $o) => $row($o, ['sent' => $day($o->created_at), 'days_ago' => (int) $o->created_at->diffInDays(now())]));

        $applications = $agent ? collect() : $this->realty()->agentApplications()->where('status', User::STATUS_PENDING)->oldest()->get(['id', 'name', 'created_at'])
            ->map(fn (User $a) => ['name' => $a->name, 'applied' => $day($a->created_at)]);

        $deals = Unit::where('realty_id', $this->realty()->id)->whereIn('status', ['reserved', 'sold'])->where('status_at', '>=', now()->subDays(7))
            ->when($agent, fn ($q) => $q->whereHas('statusOffer', fn ($o) => $o->where('agent_id', $this->user->id)))
            ->with(['project:id,name', 'statusOffer:id,buyer_name,agent_id', 'statusOffer.agent:id,name'])
            ->latest('status_at')->get()
            ->map(fn (Unit $u) => array_filter([
                'unit' => $u->name,
                'project' => $u->project?->name,
                'status' => $u->status,
                'buyer' => $u->statusOffer && $this->canSeeOfferOf($u->statusOffer->agent_id) ? $u->statusOffer->buyer_name : null,
                'agent' => $u->statusOffer?->agent?->name,
                'on' => $day($u->status_at),
                'dashboard_url' => $this->url("projects/{$u->project_id}#units"),
            ]));

        $approvals = $agent ? collect() : $terms('pending');
        $sentBack = $agent ? $terms('rejected') : collect();

        return array_filter([
            'today' => now('Asia/Manila')->format('l, M j, Y'),
            'total_things_to_do' => $answers->count() + $files->count() + $approvals->count() + $sentBack->count() + $missing->count() + $quiet->count() + $unopened->count() + $applications->count(),
            'new_buyer_answers_not_opened_yet' => $list($answers),
            'buyer_files_to_review' => $list($files),
            'custom_terms_waiting_for_your_approval' => $agent ? null : $list($approvals),
            'custom_terms_sent_back_to_you' => $agent ? $list($sentBack) : null,
            'interested_buyers_still_missing_requirements' => $list($missing),
            'opened_often_but_no_answer_yet' => $list($quiet),
            'links_not_opened_3_days_after_sending' => $list($unopened),
            'agent_applications_waiting' => $agent ? null : $list($applications) + ['dashboard_url' => $this->url('agents')],
            'good_news_reserved_or_sold_last_7_days' => $list($deals),
        ], fn ($v) => $v !== null);
    }

    /** @return Builder<Offer> The offers this person may see: the realty's, or an agent's own. */
    private function offers(): Builder
    {
        return Offer::where('realty_id', $this->realty()->id)->when($this->isAgent(), fn ($q) => $q->where('agent_id', $this->user->id));
    }

    /** @return Project|array<string, mixed> The one project meant, or an error/choice for the model. */
    private function findProject(?string $name): Project|array
    {
        if (! $name) {
            return ['error' => 'Say which project.'];
        }
        $matches = $this->realty()->projects()->where(fn ($q) => $q->where('name', 'like', "%{$name}%")->orWhere('slug', $name))->get();
        $exact = $matches->first(fn (Project $p) => strcasecmp($p->name, $name) === 0);

        return match (true) {
            $exact !== null => $exact,
            $matches->count() === 1 => $matches->first(),
            $matches->isEmpty() => ['error' => "No project called \"{$name}\". Use list_projects to see them all."],
            default => ['several_match' => $matches->pluck('name')->all()],
        };
    }

    private function canSeeOfferOf(?int $agentId): bool
    {
        return ! $this->isAgent() || $agentId === $this->user->id;
    }

    private function isAgent(): bool
    {
        return $this->user->role === User::ROLE_AGENT;
    }

    private function realty(): Realty
    {
        return $this->user->realty;
    }

    private function url(string $path): string
    {
        return "/{$this->realty()->slug}/dashboard/{$path}";
    }

    /** @return array{min: float, max: float}|null */
    private function range(mixed $min, mixed $max, bool $money = true): ?array
    {
        return $min === null ? null : ['min' => (float) $min, 'max' => (float) $max] + ($money ? ['currency' => 'PHP'] : []);
    }

    /** @param  array<string, mixed>  $args */
    private function text(array $args, string $key): ?string
    {
        $value = trim((string) ($args[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    /** A buyer may type their number or email into a message: those stay out of what's sent to OpenAI. */
    private function blankContacts(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[email hidden]', $text);

        return mb_substr(preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '[number hidden]', $text), 0, 500);
    }
}
