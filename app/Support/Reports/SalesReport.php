<?php

namespace App\Support\Reports;

use App\Models\Offer;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A sales report for a period (this week, this month, any two dates), from the live data: offers sent,
 * buyers' answers, homes reserved and sold, reservation fees confirmed, by project and by agent, compared
 * with the period before. Only what this person may see: an agent gets their own offers, a broker what
 * its firm sold. The developer's admins also count homes marked reserved or sold without an offer.
 */
class SalesReport
{
    /** The periods a report can be asked for by name, as the AI and the report page name them. */
    public const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'last_7_days' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30_days' => 'Last 30 days',
        'this_quarter' => 'This quarter',
        'this_year' => 'This year',
    ];

    /** The longest report, so a typo in a year doesn't load everything. */
    public const MAX_DAYS = 400;

    /** Rows in each list (offers sent, homes reserved or sold). */
    private const MAX_ROWS = 100;

    private const TZ = 'Asia/Manila';

    private Carbon $from;

    private Carbon $to;

    private function __construct(private readonly User $user, Carbon $from, Carbon $to)
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->endOfDay();
    }

    /**
     * The report for a named period, or for two dates (YYYY-MM-DD, Manila time).
     *
     * @throws InvalidArgumentException with a message to show when the dates don't make a period
     */
    public static function for(User $user, ?string $period = null, ?string $from = null, ?string $to = null): self
    {
        if ($from !== null || $to !== null) {
            $start = self::date($from ?? $to);
            $end = self::date($to ?? $from);
            if ($end->lt($start)) {
                throw new InvalidArgumentException('The end date is before the start date.');
            }
            if ($start->gt(now(self::TZ)->endOfDay())) {
                throw new InvalidArgumentException('That period has not started yet.');
            }
            $end = $end->min(now(self::TZ));
            if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
                throw new InvalidArgumentException('A report covers at most '.self::MAX_DAYS.' days.');
            }

            return new self($user, $start, $end);
        }

        [$start, $end] = self::range($period ?? 'this_month');

        return new self($user, $start, $end);
    }

    /**
     * A named period's first and last day today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(string $period): array
    {
        $today = now(self::TZ)->startOfDay();

        return match ($period) {
            'today' => [$today, $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'this_week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today],
            'last_week' => [$today->copy()->startOfWeek(Carbon::MONDAY)->subWeek(), $today->copy()->startOfWeek(Carbon::MONDAY)->subDay()],
            'last_7_days' => [$today->copy()->subDays(6), $today],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_30_days' => [$today->copy()->subDays(29), $today],
            'this_quarter' => [$today->copy()->startOfQuarter(), $today],
            'this_year' => [$today->copy()->startOfYear(), $today],
            default => throw new InvalidArgumentException('Unknown period. Use '.implode(', ', array_keys(self::PERIODS)).', or two dates.'),
        };
    }

    /**
     * Everything in the report.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $realty = $this->user->realty;
        [$prevFrom, $prevTo] = $this->previous();
        $now = $this->totals($this->from, $this->to);
        $before = $this->totals($prevFrom, $prevTo);
        $deals = $this->deals($this->from, $this->to);
        $sent = $this->offers()->whereBetween('offers.created_at', $this->utc($this->from, $this->to))
            ->with(['unit:id,name', 'project:id,name', 'agent:id,name', 'responses:id,offer_id,kind,created_at'])->latest('offers.created_at')->get();
        $answers = $this->answers($this->from, $this->to);
        $projects = $this->projects($sent, $answers, $deals);
        $agents = $this->isAgent() ? [] : $this->agents($sent, $answers, $deals);

        return [
            'realty' => $realty->publicArray(),
            'period' => [
                'from' => $this->from->toDateString(),
                'to' => $this->to->toDateString(),
                'label' => $this->label(),
                'dates' => self::dates($this->from, $this->to),
                'days' => (int) $this->from->diffInDays($this->to->copy()->startOfDay()) + 1,
                'compared_with' => self::dates($prevFrom, $prevTo),
            ],
            'prepared' => [
                'by' => $this->user->name,
                'at' => now()->toIso8601String(),
                'scope' => $this->isAgent() ? 'Your own offers' : ($realty->isDeveloper() ? "All of {$realty->name}'s offers and units" : "The offers {$realty->name} made"),
            ],
            'totals' => $now,
            'previous' => $before,
            'highlights' => $this->highlights($now, $before, $projects, $agents),
            'projects' => $projects,
            'agents' => $agents,
            'deals' => $deals->take(self::MAX_ROWS)->values()->all(),
            'offers' => $sent->take(self::MAX_ROWS)->map(fn (Offer $o) => [
                'date' => $o->created_at->timezone(self::TZ)->toDateString(),
                'code' => $o->code,
                'buyer' => $o->buyer_name,
                'unit' => $o->unit?->name,
                'project' => $o->project?->name,
                'agent' => $this->isAgent() ? null : $o->agent?->name,
                'price' => (float) $o->price,
                'opened' => (int) $o->views,
                'answer' => ($r = $o->responses->sortByDesc('created_at')->first()) ? (OfferResponse::LABELS[$r->kind] ?? $r->kind) : null,
                'status' => $o->status,
            ])->all(),
            'inventory' => $this->inventory(),
        ];
    }

    /** "This week", "Last month", or the dates when they aren't a named period today. */
    public function label(): string
    {
        foreach (array_keys(self::PERIODS) as $period) {
            [$start, $end] = self::range($period);
            if ($start->isSameDay($this->from) && $end->isSameDay($this->to)) {
                return self::PERIODS[$period];
            }
        }

        return self::dates($this->from, $this->to);
    }

    /** "Oct 12 – 16, 2026", "Sep 29 – Oct 3, 2026", "Oct 16, 2026". */
    public static function dates(Carbon $from, Carbon $to): string
    {
        return match (true) {
            $from->isSameDay($to) => $from->format('M j, Y'),
            $from->isSameMonth($to) => $from->format('M j').' – '.$to->format('j, Y'),
            $from->isSameYear($to) => $from->format('M j').' – '.$to->format('M j, Y'),
            default => $from->format('M j, Y').' – '.$to->format('M j, Y'),
        };
    }

    /**
     * The period before, to compare with: the same days of the month (or week, quarter, year) before, else as many days just before.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previous(): array
    {
        $days = (int) $this->from->diffInDays($this->to->copy()->startOfDay()) + 1;
        // One month from its 1st: the month before, whole or up to the same day.
        if ($this->from->day === 1 && $this->from->isSameMonth($this->to)) {
            $start = $this->from->copy()->subMonthNoOverflow();
            $end = $this->to->isLastOfMonth() ? $start->copy()->endOfMonth() : $start->copy()->addDays(min($days, $start->daysInMonth) - 1)->endOfDay();

            return [$start, $end];
        }
        if ($days === 7 || ($this->from->isMonday() && $days < 7)) {
            return [$this->from->copy()->subWeek(), $this->to->copy()->subWeek()];
        }

        return [$this->from->copy()->subDays($days), $this->from->copy()->subDay()->endOfDay()];
    }

    /** @return array<string, int|float> */
    private function totals(Carbon $from, Carbon $to): array
    {
        $range = $this->utc($from, $to);
        $sent = $this->offers()->whereBetween('offers.created_at', $range);
        $answers = $this->answers($from, $to);
        $deals = $this->deals($from, $to);
        $confirmed = $this->offers()->where('reservation_status', 'confirmed')->whereBetween('reservation_confirmed_at', $range);

        return [
            'offers_sent' => (clone $sent)->count(),
            'opened' => (clone $sent)->where('views', '>', 0)->count(),
            'answers' => $answers->count(),
            'interested' => $answers->where('kind', 'interested')->pluck('offer_id')->unique()->count(),
            'questions' => $answers->where('kind', 'question')->count(),
            'not_interested' => $answers->where('kind', 'not_interested')->pluck('offer_id')->unique()->count(),
            'reserved' => $deals->where('status', 'reserved')->count(),
            'sold' => $deals->where('status', 'sold')->count(),
            'value' => (float) $deals->sum('price'),
            'reservations_confirmed' => (clone $confirmed)->count(),
            'fees_confirmed' => (float) (clone $confirmed)->sum('reservation_fee'),
        ];
    }

    /**
     * Buyers' answers in the period, on offers this person may see.
     *
     * @return Collection<int, OfferResponse>
     */
    private function answers(Carbon $from, Carbon $to): Collection
    {
        return OfferResponse::whereIn('offer_id', $this->offers()->select('offers.id'))->whereBetween('created_at', $this->utc($from, $to))
            ->get(['id', 'offer_id', 'kind', 'created_at']);
    }

    /**
     * Homes reserved or sold in the period (their latest status change), newest first, at the offer's price when there was one.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function deals(Carbon $from, Carbon $to): Collection
    {
        $allUnits = ! $this->isAgent() && $this->user->realty->isDeveloper();

        return Unit::whereIn('status', ['reserved', 'sold'])->whereBetween('status_at', $this->utc($from, $to))
            ->when(
                $allUnits,
                fn ($q) => $q->whereIn('project_id', Project::where('realty_id', $this->user->realty->inventoryId())->select('id')),
                fn ($q) => $q->whereIn('status_offer_id', $this->offers()->select('offers.id')),
            )
            ->with(['project:id,name', 'statusOffer:id,buyer_name,price,agent_id', 'statusOffer.agent:id,name'])->latest('status_at')->get()
            ->map(fn (Unit $u) => [
                'date' => $u->status_at->timezone(self::TZ)->toDateString(),
                'status' => $u->status,
                'unit' => $u->name,
                'unit_id' => $u->id,
                'project' => $u->project?->name,
                'project_id' => $u->project_id,
                'buyer' => $u->statusOffer?->buyer_name,
                'agent' => $this->isAgent() ? null : $u->statusOffer?->agent?->name,
                'agent_id' => $u->statusOffer?->agent_id,
                'price' => (float) ($u->statusOffer?->price ?? $u->price ?? 0),
            ]);
    }

    /**
     * Each project with something in the period, busiest first, with what is still available now.
     *
     * @param  Collection<int, Offer>  $sent
     * @param  Collection<int, OfferResponse>  $answers
     * @param  Collection<int, array<string, mixed>>  $deals
     * @return array<int, array<string, mixed>>
     */
    private function projects(Collection $sent, Collection $answers, Collection $deals): array
    {
        $offerProject = $this->offers()->whereIn('offers.id', $answers->pluck('offer_id')->unique())->pluck('project_id', 'offers.id');
        $ids = $sent->pluck('project_id')->merge($offerProject->values())->merge($deals->pluck('project_id'))->filter()->unique();
        if ($ids->isEmpty()) {
            return [];
        }
        $projects = Project::whereIn('id', $ids)->withCount(['units as available' => fn ($q) => $q->where('status', 'available')])->get(['id', 'name', 'location']);

        return $projects->map(function (Project $p) use ($sent, $answers, $deals, $offerProject) {
            $mine = $deals->where('project_id', $p->id);
            $answered = $answers->filter(fn (OfferResponse $r) => (int) ($offerProject[$r->offer_id] ?? 0) === $p->id);

            return [
                'name' => $p->name,
                'location' => $p->location,
                'offers' => $sent->where('project_id', $p->id)->count(),
                'interested' => $answered->where('kind', 'interested')->pluck('offer_id')->unique()->count(),
                'reserved' => $mine->where('status', 'reserved')->count(),
                'sold' => $mine->where('status', 'sold')->count(),
                'value' => (float) $mine->sum('price'),
                'available_now' => (int) $p->available,
            ];
        })->sortByDesc(fn (array $p) => [$p['reserved'] + $p['sold'], $p['offers'], $p['interested']])->values()->all();
    }

    /**
     * Each agent with something in the period, best first.
     *
     * @param  Collection<int, Offer>  $sent
     * @param  Collection<int, OfferResponse>  $answers
     * @param  Collection<int, array<string, mixed>>  $deals
     * @return array<int, array<string, mixed>>
     */
    private function agents(Collection $sent, Collection $answers, Collection $deals): array
    {
        $offerAgent = $this->offers()->whereIn('offers.id', $answers->pluck('offer_id')->unique())->pluck('agent_id', 'offers.id');
        $ids = $sent->pluck('agent_id')->merge($offerAgent->values())->merge($deals->pluck('agent_id'))->filter()->unique();
        if ($ids->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $ids)->get(['id', 'name', 'photo_path'])->map(function (User $a) use ($sent, $answers, $deals, $offerAgent) {
            $mine = $deals->where('agent_id', $a->id);

            return [
                'name' => $a->name,
                'photo' => $a->photoUrl(),
                'offers' => $sent->where('agent_id', $a->id)->count(),
                'interested' => $answers->filter(fn (OfferResponse $r) => $r->kind === 'interested' && (int) ($offerAgent[$r->offer_id] ?? 0) === $a->id)->pluck('offer_id')->unique()->count(),
                'deals' => $mine->count(),
                'value' => (float) $mine->sum('price'),
            ];
        })->sortByDesc(fn (array $a) => [$a['deals'], $a['offers'], $a['interested']])->values()->all();
    }

    /** @return array<string, int> Every unit and lot in the open projects, by status, today. */
    private function inventory(): array
    {
        $counts = Unit::whereIn('project_id', Project::where('realty_id', $this->user->realty->inventoryId())->where('status', 'active')->select('id'))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['available' => (int) ($counts['available'] ?? 0), 'reserved' => (int) ($counts['reserved'] ?? 0), 'sold' => (int) ($counts['sold'] ?? 0)];
    }

    /**
     * The report in a few plain sentences, worked out from the numbers (never written by the AI).
     *
     * @param  array<string, int|float>  $now
     * @param  array<string, int|float>  $before
     * @param  array<int, array<string, mixed>>  $projects
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, string>
     */
    private function highlights(array $now, array $before, array $projects, array $agents): array
    {
        $plural = fn (int|float $n, string $one, ?string $many = null) => number_format($n).' '.($n == 1 ? $one : ($many ?? $one.'s'));
        $peso = fn (float $n) => '₱'.number_format($n, fmod($n, 1) ? 2 : 0);
        $change = function (int|float $n, int|float $was): string {
            if ($n == $was) {
                return 'the same as the period before';
            }

            return ($n > $was ? 'up' : 'down').' from '.number_format($was).' the period before';
        };
        $deals = $now['reserved'] + $now['sold'];
        $lines = [];

        $lines[] = $now['offers_sent'] > 0
            ? $plural($now['offers_sent'], 'offer').' sent, '.$change($now['offers_sent'], $before['offers_sent']).'; '.number_format($now['opened']).' opened by the buyer so far.'
            : 'No offers were sent'.($before['offers_sent'] > 0 ? ' (there were '.number_format($before['offers_sent']).' the period before).' : '.');
        if ($deals > 0) {
            $lines[] = $plural($deals, 'home').' reserved or sold ('.number_format($now['reserved']).' reserved, '.number_format($now['sold']).' sold), worth '.$peso($now['value']).'; '.$change($deals, $before['reserved'] + $before['sold']).'.';
        }
        if ($now['answers'] > 0) {
            $lines[] = $plural($now['answers'], 'answer').' from buyers: '.number_format($now['interested']).' interested, '.$plural($now['questions'], 'question').', '.number_format($now['not_interested']).' not interested.';
        }
        if ($now['reservations_confirmed'] > 0) {
            $lines[] = $plural($now['reservations_confirmed'], 'reservation').' confirmed, '.$peso($now['fees_confirmed']).' in reservation fees.';
        }
        if (($top = $projects[0] ?? null) && ($top['offers'] + $top['reserved'] + $top['sold']) > 0 && count($projects) > 1) {
            $lines[] = "Busiest project: {$top['name']} (".$plural($top['offers'], 'offer').', '.number_format($top['reserved'] + $top['sold']).' reserved or sold).';
        }
        if (($best = $agents[0] ?? null) && count($agents) > 1) {
            $lines[] = "Top agent: {$best['name']} (".$plural($best['offers'], 'offer').', '.$plural($best['deals'], 'deal').').';
        }

        return $lines;
    }

    /** @return Builder<Offer> The offers this person may see. */
    private function offers(): Builder
    {
        return Offer::visibleTo($this->user);
    }

    private function isAgent(): bool
    {
        return $this->user->role === User::ROLE_AGENT;
    }

    /** @return array{0: Carbon, 1: Carbon} The period's start and end as stored (UTC). */
    private function utc(Carbon $from, Carbon $to): array
    {
        return [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()];
    }

    private static function date(?string $value): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Give dates as YYYY-MM-DD.');
        }
        $date = Carbon::createFromFormat('!Y-m-d', $value, self::TZ);
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$value} is not a date.");
        }

        return $date;
    }
}
