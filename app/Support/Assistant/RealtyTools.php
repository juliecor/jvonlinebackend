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
use Illuminate\Support\Collection;

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

    public function __construct(private readonly User $user) {}

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
            $tool('search_units', 'Find units: by project, status, type, price range or unit name. Returns how many match and up to 25 of them.', [
                'project' => $text('Project name, or part of it.'),
                'status' => ['type' => 'string', 'enum' => ['available', 'reserved', 'sold']],
                'unit_type' => $text('e.g. "Studio Unit", "1BR Unit", "Two-Storey Townhouse".'),
                'min_price' => $number('Lowest price in pesos.'),
                'max_price' => $number('Highest price in pesos.'),
                'query' => $text('Part of a unit name, e.g. "Bldg J" or "Block 24".'),
                'sort' => ['type' => 'string', 'enum' => ['price_low', 'price_high', 'name']],
            ]),
            $tool('list_offers', "Sales offers sent to buyers, newest first, with each one's progress. Filter by status, project, agent, buyer, the buyer's answer, or what still needs doing.", [
                'status' => ['type' => 'string', 'enum' => ['active', 'void', 'all']],
                'project' => $text('Project name, or part of it.'),
                'agent' => $text("Agent's name, or part of it."),
                'buyer' => $text("Buyer's name, or part of it."),
                'response' => ['type' => 'string', 'enum' => ['interested', 'question', 'not_interested', 'none']],
                'needs' => ['type' => 'string', 'enum' => ['documents', 'review', 'approval'], 'description' => 'documents: buyer still missing requirements; review: buyer files waiting to be checked; approval: custom terms waiting.'],
            ]),
            $tool('offer_details', "One offer: the buyer's payment schedule, each requirement's state, the buyer's answers and what happened when.", [
                'offer' => $text("The offer code (e.g. 3XT3WEF4P7) or the buyer's name."),
            ]),
            $tool('buyer_responses', "Buyers' answers to offers (interested, a question, not interested), newest first.", [
                'days' => ['type' => 'integer', 'description' => 'Only the last N days.'],
                'kind' => ['type' => 'string', 'enum' => ['interested', 'question', 'not_interested']],
            ]),
            $tool('list_agents', "The realty's agents: who is active or waiting for approval, and each one's offers and buyer responses. Realty admins only."),
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
            'list_offers' => $this->listOffers($args),
            'offer_details' => $this->offerDetails($args),
            'buyer_responses' => $this->buyerResponses($args),
            'list_agents' => $this->listAgents(),
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
        $units = $project->units()->get(['unit_type', 'status', 'price', 'area_sqm']);

        return [
            'name' => $project->name,
            'location' => $project->location,
            'region' => $project->region,
            'stage' => $project->stage,
            'open_for_offers' => $project->status === 'active',
            'turnover' => $project->completion_date?->toDateString(),
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
        $responses = OfferResponse::where('realty_id', $realty->id)->join('offers', 'offers.id', '=', 'offer_responses.offer_id')
            ->selectRaw('offers.agent_id, count(*) as n')->groupBy('offers.agent_id')->pluck('n', 'offers.agent_id');

        return [
            'count' => $agents->count(),
            'invitations_open' => $realty->agentInvitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
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
            'custom_terms' => $o->approval_status,
            'unit_status' => $o->unit && $o->unit->status_offer_id === $o->id ? $o->unit->status : null,
            'buyer_login_set' => $o->isLocked(),
            'dashboard_url' => $this->url("offers/{$o->id}"),
        ];
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
