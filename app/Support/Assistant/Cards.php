<?php

namespace App\Support\Assistant;

use App\Models\Offer;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\RequirementType;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The cards the AI shows under an answer: units, offers (buyers), projects
 * and payment schedules. An answer keeps only references (ids, and a payment's
 * terms); the cards are drawn from the live data every time, only from the
 * person's own realty, and agents only ever get their own offers.
 */
class Cards
{
    /** At most this many cards of each kind under one answer. */
    public const MAX = ['unit' => 6, 'offer' => 6, 'project' => 6, 'payment' => 3];

    /**
     * The list with one more card, unless it's already there or that kind is full.
     *
     * @param  array<int, array<string, mixed>>  $refs
     * @param  array<string, mixed>  $ref  e.g. ['type' => 'unit', 'id' => 12]
     * @return array<int, array<string, mixed>>
     */
    public static function add(array $refs, array $ref): array
    {
        $same = collect($refs)->where('type', $ref['type']);
        $there = $ref['type'] === 'payment' ? $same->contains(fn (array $r) => $r == $ref) : $same->contains('id', $ref['id']);
        if ($there || $same->count() >= self::MAX[$ref['type']]) {
            return $refs;
        }

        return [...$refs, $ref];
    }

    /**
     * The cards for these references, in order; anything gone (or not this person's to see) is left out.
     *
     * @param  array<int, mixed>  $refs
     * @return array<int, array<string, mixed>>
     */
    public static function for(User $user, array $refs): array
    {
        $refs = collect($refs)->filter(fn ($r) => is_array($r) && isset(self::MAX[$r['type'] ?? '']))->values();
        if ($refs->isEmpty()) {
            return [];
        }
        $ids = fn (string $type, string $key = 'id') => $refs->where('type', $type)->pluck($key)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $units = Unit::where('realty_id', $user->realty_id)->whereIn('id', [...$ids('unit'), ...$ids('payment', 'unit')])->with('project')->get()->keyBy('id');
        $offers = Offer::where('realty_id', $user->realty_id)->when($user->role === User::ROLE_AGENT, fn ($q) => $q->where('agent_id', $user->id))
            ->whereIn('id', $ids('offer'))->with(['project', 'unit', 'agent:id,name', 'responses', 'documents'])->get()->keyBy('id');
        $projects = Project::where('realty_id', $user->realty_id)->whereIn('id', [...$ids('project'), ...$ids('payment', 'project')])
            ->withCount([
                'units',
                'units as available' => fn ($q) => $q->where('status', 'available'),
                'units as reserved' => fn ($q) => $q->where('status', 'reserved'),
                'units as sold' => fn ($q) => $q->where('status', 'sold'),
                'units as offerable' => fn ($q) => $q->where('status', 'available')->whereNotNull('price'),
                'paymentPlans',
            ])
            ->withMin('units', 'price')->withMax('units', 'price')->get()->keyBy('id');
        $models = UnitType::whereIn('project_id', $units->pluck('project_id')->merge($offers->pluck('project_id'))->unique())->get();
        $types = $offers->isEmpty() ? collect() : RequirementType::where('realty_id', $user->realty_id)->orderBy('sort')->orderBy('id')->get();

        return $refs->map(fn (array $r) => match ($r['type']) {
            'unit' => ($u = $units->get((int) ($r['id'] ?? 0))) ? self::unit($u, $models) : null,
            'offer' => ($o = $offers->get((int) ($r['id'] ?? 0))) ? self::offer($o, $models, $types, $user) : null,
            'project' => ($p = $projects->get((int) ($r['id'] ?? 0))) ? self::project($p) : null,
            'payment' => self::payment($r, $units, $projects),
        })->filter()->values()->all();
    }

    /**
     * @param  Collection<int, UnitType>  $models
     * @return array<string, mixed>
     */
    private static function unit(Unit $u, Collection $models): array
    {
        return [
            'type' => 'unit',
            'id' => $u->id,
            'name' => $u->name,
            'project' => ['id' => $u->project_id, 'name' => $u->project?->name, 'location' => $u->project?->location],
            'unit_type' => $u->unit_type,
            'floor' => $u->floor,
            'area_sqm' => $u->area_sqm !== null ? (float) $u->area_sqm : null,
            'price' => $u->price !== null ? (float) $u->price : null,
            'status' => $u->status,
            'photo' => $u->photo($models, $u->project),
            // The New offer form lists available units with a price, in projects open for offers.
            'can_offer' => $u->status === 'available' && $u->price !== null && $u->project?->status === 'active',
        ];
    }

    /**
     * A buyer's offer as the offers list shows it: answer, documents, opens.
     *
     * @param  Collection<int, UnitType>  $models
     * @param  Collection<int, RequirementType>  $types
     * @return array<string, mixed>
     */
    private static function offer(Offer $o, Collection $models, Collection $types, User $user): array
    {
        $latest = $o->responses->sortByDesc('created_at')->first();

        return [
            'type' => 'offer',
            'id' => $o->id,
            'code' => $o->code,
            'buyer' => $o->buyer_name,
            'status' => $o->status,
            'project' => $o->project?->name,
            'unit' => $o->unit?->name,
            'price' => (float) $o->price,
            'agent' => $user->role === User::ROLE_AGENT ? null : $o->agent?->name,
            'photo' => $o->photo($models),
            'sent' => $o->created_at->toIso8601String(),
            'views' => (int) $o->views,
            'last_viewed_at' => $o->last_viewed_at?->toIso8601String(),
            'answer' => $latest ? ['kind' => $latest->kind, 'label' => OfferResponse::LABELS[$latest->kind] ?? $latest->kind, 'at' => $latest->created_at->toIso8601String()] : null,
            'new_answers' => $o->responses->whereNull('seen_at')->count(),
            'requirements' => $o->requirementSummary($types),
            'approval_status' => $o->approval_status,
            'unit_status' => $o->unit && $o->unit->status_offer_id === $o->id && $o->unit->status !== 'available' ? $o->unit->status : null,
            // The buyer's link, once the buyer can open it.
            'url' => $o->status === 'active' && ! $o->awaitingApproval() ? Offer::url($o->code) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function project(Project $p): array
    {
        return [
            'type' => 'project',
            'id' => $p->id,
            'name' => $p->name,
            'location' => $p->location,
            'stage' => $p->stage,
            'open' => $p->status === 'active',
            'photo' => $p->hero_urls[0] ?? $p->cover_url,
            'units' => ['total' => (int) $p->units_count, 'available' => (int) $p->available, 'reserved' => (int) $p->reserved, 'sold' => (int) $p->sold],
            'price' => $p->units_min_price === null ? null : ['min' => (float) $p->units_min_price, 'max' => (float) $p->units_max_price],
            'plans' => (int) $p->payment_plans_count,
            'can_offer' => $p->status === 'active' && $p->offerable > 0,
        ];
    }

    /**
     * A payment schedule, worked out again from its terms at the price the AI
     * used (the same code that builds offers: a ₱20,000 fee stays ₱20,000),
     * with the unit's price now if it changed since, and what "Make offer
     * with these terms" needs.
     *
     * @param  array<string, mixed>  $r
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>|null
     */
    private static function payment(array $r, Collection $units, Collection $projects): ?array
    {
        $unit = isset($r['unit']) ? $units->get((int) $r['unit']) : null;
        $project = $unit ? $projects->get($unit->project_id) ?? $unit->project : (isset($r['project']) ? $projects->get((int) $r['project']) : null);
        $price = (float) ($r['price'] ?? $unit?->price ?? 0);
        $milestones = is_array($r['milestones'] ?? null) ? array_values($r['milestones']) : [];
        if ((isset($r['unit']) && ! $unit) || ! $price || $milestones === []) {
            return null;
        }
        $date = Carbon::parse($r['date'] ?? now('Asia/Manila')->toDateString(), 'Asia/Manila');
        $schedule = Offer::buildSchedule($price, $milestones, $date, $project?->completion_date);

        return [
            'type' => 'payment',
            'unit' => $unit ? ['id' => $unit->id, 'name' => $unit->name] : null,
            'project' => $project ? ['id' => $project->id, 'name' => $project->name] : null,
            'price' => $price,
            'price_now' => $unit && $unit->price !== null && abs((float) $unit->price - $price) >= 0.01 ? (float) $unit->price : null,
            'terms' => (string) ($r['terms'] ?? 'Custom terms'),
            'plan_id' => isset($r['plan']) ? (int) $r['plan'] : null,
            'purchase_date' => $date->toDateString(),
            'payments' => collect($schedule)->map(fn (array $row) => [
                'label' => $row['label'],
                'percent' => round((float) $row['percent'], 2),
                'amount' => (float) $row['amount'],
                'date' => $row['date'],
                'months' => $row['months'] ?? null,
                'monthly' => $row['monthly'] ?? null,
                'end_date' => $row['end_date'] ?? null,
                'last_month' => isset($row['installments']) ? end($row['installments'])['amount'] : null,
            ])->all(),
            'milestones' => $milestones,
            'can_offer' => $unit !== null && $unit->status === 'available' && $unit->price !== null && $project?->status === 'active',
        ];
    }
}
