<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** One unit + one payment plan, prepared by an agent for one buyer. The code is the public link. */
#[Fillable(['realty_id', 'broker_realty_id', 'project_id', 'unit_id', 'payment_plan_id', 'agent_id', 'code', 'buyer_name', 'buyer_email', 'buyer_phone', 'access_username', 'access_password', 'purchase_date', 'expires_at', 'price', 'schedule', 'fee_notes', 'status', 'buyer_details', 'details_submitted_at', 'offer_emailed_at', 'last_reminded_at', 'reminders_sent', 'custom_milestones', 'approval_status', 'approval_reason', 'approval_note', 'approved_by', 'approved_at', 'email_on_approval'])]
#[Hidden(['access_password'])]
class Offer extends Model
{
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d', 'price' => 'decimal:2', 'schedule' => 'array', 'first_viewed_at' => 'datetime', 'last_viewed_at' => 'datetime',
            'buyer_details' => 'array', 'details_submitted_at' => 'datetime', 'offer_emailed_at' => 'datetime', 'last_reminded_at' => 'datetime',
            'custom_milestones' => 'array', 'approved_at' => 'datetime', 'email_on_approval' => 'boolean',
            'access_password' => 'encrypted', 'expires_at' => 'datetime',
        ];
    }

    /** The developer whose unit this is (Johndorf): it owns the offer's approvals and buyer requirements. */
    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
    }

    /** The accredited realty that made the sale; null when the developer's own people did. */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Realty::class, 'broker_realty_id');
    }

    /** The firm the buyer deals with: the broker, else the developer. */
    public function sellerName(): string
    {
        return ($this->broker ?? $this->realty)->name;
    }

    /**
     * The one rule for who sees an offer, used by the dashboard, the buyer page's
     * "is this a staff preview" check and unit holds (Offer::visibleTo($user)):
     *  - an agent: their own offers;
     *  - a developer's staff: every offer on its inventory, whichever firm sold it;
     *  - a broker's staff: the offers their firm sold.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        $realty = $user->realty;
        $query->where($query->qualifyColumn('realty_id'), $realty->inventoryId());

        if ($user->role === User::ROLE_AGENT) {
            return $query->where($query->qualifyColumn('agent_id'), $user->id);
        }

        return $realty->isDeveloper() ? $query : $query->where($query->qualifyColumn('broker_realty_id'), $realty->id);
    }

    /** The same rule as the visibleTo scope, for one offer already in hand. */
    public function isVisibleTo(User $user): bool
    {
        $realty = $user->realty;
        if (! $realty || $this->realty_id !== $realty->inventoryId()) {
            return false;
        }
        if ($user->role === User::ROLE_AGENT) {
            return $this->agent_id === $user->id;
        }

        return $realty->isDeveloper() || $this->broker_realty_id === $realty->id;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(OfferResponse::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Custom terms the admin hasn't approved (yet, or sent back): the buyer can't open the link. */
    public function awaitingApproval(): bool
    {
        return in_array($this->approval_status, ['pending', 'rejected'], true);
    }

    /** The agent set a username and password: the buyer has to type them to open the link. */
    public function isLocked(): bool
    {
        return filled($this->access_username) && filled($this->getRawOriginal('access_password'));
    }

    /** The buyer's username (any case, spaces around it ignored) and password (exactly). */
    public function checkLogin(string $username, string $password): bool
    {
        return $this->isLocked()
            && strcasecmp(trim($username), (string) $this->access_username) === 0
            && hash_equals((string) $this->access_password, $password);
    }

    /**
     * What the buyer's browser keeps after signing in. It is tied to the
     * stored password, so changing the password signs everyone out.
     */
    public function accessToken(): string
    {
        return hash_hmac('sha256', "offer-access:{$this->id}:{$this->access_username}:".$this->getRawOriginal('access_password'), (string) config('app.key'));
    }

    /** How long the sign-in link in a reminder email keeps working. */
    public const ENTRY_DAYS = 30;

    /**
     * The key in a reminder email's link: it signs the buyer in without typing the login, and stops working
     * after ENTRY_DAYS or as soon as the agent changes the username or password. Only good for opening this offer.
     */
    public function entryToken(): string
    {
        $expires = now()->addDays(self::ENTRY_DAYS)->timestamp;

        return $expires.'.'.$this->entrySignature($expires);
    }

    public function checkEntryToken(?string $token): bool
    {
        if (! is_string($token) || ! preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', $token, $m)) {
            return false;
        }

        return (int) $m[1] > now()->timestamp && hash_equals($this->entrySignature((int) $m[1]), $m[2]);
    }

    private function entrySignature(int $expires): string
    {
        return hash_hmac('sha256', "offer-entry:{$this->id}:{$expires}:{$this->access_username}:".$this->getRawOriginal('access_password'), (string) config('app.key'));
    }

    /**
     * Where a reminder sends the buyer to finish their requirements. A private offer's link carries the entry key,
     * so it opens already signed in, on the requirements, with what's missing marked.
     */
    public function requirementsUrl(): string
    {
        if ($this->isLocked()) {
            return self::url($this->code).'/enter?k='.$this->entryToken();
        }

        return self::url($this->code).'?guide=1#requirements';
    }

    /** Whether this visitor may open the offer: it has no login, or they signed in to it. */
    public function grantsAccess(?string $token): bool
    {
        return ! $this->isLocked() || (is_string($token) && $token !== '' && hash_equals($this->accessToken(), $token));
    }

    public const EXPIRED_MESSAGE = 'This offer has expired. Please ask your agent to extend it.';

    /** The agent's "valid for" choice has run out: the buyer can't open or answer it until it is extended. No date means it never expires. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Active and, if it has custom terms, approved: the buyer can open and act on it. */
    public function isLive(): bool
    {
        return $this->status === 'active' && ! $this->awaitingApproval();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OfferDocument::class);
    }

    /** Where reminders and the offer itself are emailed: the details the buyer gave, else what the agent typed. */
    public function buyerEmail(): ?string
    {
        return ($this->buyer_details['email'] ?? null) ?: $this->buyer_email;
    }

    /**
     * This buyer's checklist: each requirement with what it needs from them
     * ('required' / 'optional' / 'not_needed'), where it stands
     * ('missing' / 'review' / 'approved' / 'rejected') and its files.
     * $internal adds what only the realty sees (who reviewed, file type).
     *
     * @param  Collection<int, RequirementType>|null  $types  the realty's types, when listing many offers
     * @return array<int, array<string, mixed>>
     */
    public function requirementList(bool $internal = false, $types = null): array
    {
        $docs = $this->documents->sortByDesc('id')->groupBy('requirement_type_id');
        $types ??= RequirementType::where('realty_id', $this->realty_id)->orderBy('sort')->orderBy('id')->get();

        return $types
            // A hidden requirement still shows on offers where the buyer already sent files for it.
            ->filter(fn (RequirementType $t) => $t->active || ($internal && $docs->has($t->id)))
            ->map(function (RequirementType $t) use ($docs, $internal) {
                $files = $docs->get($t->id, collect());
                $state = match (true) {
                    $files->contains('status', 'pending') => 'review',
                    $files->contains('status', 'approved') => 'approved',
                    $files->isNotEmpty() => 'rejected',
                    default => 'missing',
                };

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'help' => $t->help,
                    'applies' => $t->applies,
                    'needed' => $t->neededFor($this->details_submitted_at ? $this->buyer_details : null),
                    'state' => $state,
                    'note' => $state === 'rejected' ? $files->first()->note : null,
                    'files' => $files->map(fn (OfferDocument $d) => [
                        'id' => $d->id,
                        'name' => $d->original_name,
                        'size' => $d->size,
                        'status' => $d->status,
                        'note' => $d->note,
                        'uploaded_at' => $d->created_at,
                    ] + ($internal ? ['mime' => $d->mime, 'reviewed_at' => $d->reviewed_at, 'reviewed_by' => $d->reviewer?->name] : []))->values()->all(),
                ];
            })->values()->all();
    }

    /** The numbers the offer lists show: required items sent / approved, files waiting for review, details in or not. */
    public function requirementSummary($types = null): array
    {
        $list = collect($this->requirementList(false, $types));
        $required = $list->where('needed', 'required');

        return [
            'required' => $required->count(),
            'submitted' => $required->whereIn('state', ['review', 'approved'])->count(),
            'approved' => $required->where('state', 'approved')->count(),
            'missing' => $required->whereIn('state', ['missing', 'rejected'])->count(),
            'to_review' => $this->documents->where('status', 'pending')->count(),
            'details' => $this->details_submitted_at !== null,
        ];
    }

    /** The buyer's link on the Next.js site. */
    public static function url(string $code): string
    {
        return rtrim(config('app.frontend_url'), '/').'/offer/'.$code;
    }

    public static function newCode(): string
    {
        do {
            // No look-alike letters, so a buyer can read it over the phone.
            $code = strtoupper(Str::random(10));
            $code = strtr($code, ['O' => '7', 'I' => '3', 'L' => '4']);
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /**
     * Turn milestones into dated amounts for this price. The last milestone
     * takes the rounding so the schedule always adds up to the price. A
     * milestone spread over months lists each monthly payment; the last
     * month takes that milestone's rounding.
     *
     * @param  array<int, array{label: string, percent: float|int|string, days: int|null, months?: int|null}>  $milestones
     * @return array<int, array<string, mixed>>
     */
    public static function buildSchedule(float $price, array $milestones, CarbonInterface $purchaseDate, ?CarbonInterface $completionDate): array
    {
        $rows = [];
        $running = 0.0;
        $count = count($milestones);
        foreach (array_values($milestones) as $i => $m) {
            $percent = (float) $m['percent'];
            $amount = $i === $count - 1 ? round($price - $running, 2) : round($price * $percent / 100, 2);
            $running += $amount;
            $due = $m['days'] === null || $m['days'] === '' ? $completionDate : $purchaseDate->copy()->addDays((int) $m['days']);
            $row = ['label' => $m['label'], 'percent' => $percent, 'date' => $due?->toDateString(), 'amount' => $amount];

            $months = (int) ($m['months'] ?? 0);
            if ($months >= 2 && $due) {
                $each = floor($amount / $months * 100) / 100;
                $installments = [];
                for ($n = 0; $n < $months; $n++) {
                    $installments[] = [
                        'n' => $n + 1,
                        'date' => $due->copy()->addMonthsNoOverflow($n)->toDateString(),
                        'amount' => $n === $months - 1 ? round($amount - $each * ($months - 1), 2) : $each,
                    ];
                }
                $row += ['months' => $months, 'monthly' => $each, 'end_date' => end($installments)['date'], 'installments' => $installments];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A picture for the dashboard: the unit's (its house model's photo, else the
     * project's, else its floor plan — see Unit::photo()). Pass the realty's
     * models when listing many offers, so it isn't one query each.
     *
     * @param  Collection<int, UnitType>|null  $models
     */
    public function photo(?Collection $models = null): ?string
    {
        return $this->unit?->photo($models, $this->project) ?? $this->project?->hero_urls[0] ?? $this->project?->cover_url;
    }

    /**
     * What a buyer sees before signing in: the sales-offer sheet. Property, price, plan and where it is,
     * nothing about the buyer, their contact details, documents or the login.
     */
    public function sheetArray(): array
    {
        $this->loadMissing(['realty', 'broker', 'project', 'unit', 'agent']);
        $project = $this->project;
        $unit = $this->unit;

        return [
            'price' => (float) $this->price,
            'schedule' => $this->schedule,
            'fee_notes' => $this->fee_notes,
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            'project' => [
                'name' => $project?->name,
                'location' => $project?->location,
                'lat' => $project?->lat,
                'lng' => $project?->lng,
                'completion_date' => $project?->completion_date?->toDateString(),
            ],
            'unit' => [
                'name' => $unit?->name,
                'unit_type' => $unit?->unit_type,
                'category' => $unit?->category,
                'area_sqm' => $unit?->area_sqm !== null ? (float) $unit->area_sqm : null,
            ],
            'broker' => $this->broker?->name,
        ];
    }

    /** Everything the buyer's page shows. */
    public function publicArray(): array
    {
        $this->loadMissing(['realty', 'broker', 'project', 'unit', 'agent']);
        $project = $this->project;
        $unit = $this->unit;
        // The model on the project's public page that this unit is (renders + spec sheet), if there is one.
        $model = UnitType::where('project_id', $project->id)
            ->whereIn('name', array_values(array_filter([$unit->unit_type, $unit->name])))
            ->first();

        return [
            'code' => $this->code,
            'status' => $this->status,
            'buyer_name' => $this->buyer_name,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'price' => (float) $this->price,
            'schedule' => $this->schedule,
            'fee_notes' => $this->fee_notes,
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            'realty' => $this->realty->publicArray() + ['phone' => $this->realty->phone, 'email' => $this->realty->email, 'address' => $this->realty->address],
            'project' => [
                'name' => $project->name,
                'location' => $project->location,
                'region' => $project->region,
                'stage' => $project->stage,
                'lat' => $project->lat,
                'lng' => $project->lng,
                'description' => $project->description,
                'cover_url' => $project->cover_url,
                'hero' => $project->hero_urls ?: array_values(array_filter([$project->cover_url])),
                'site_plans' => $project->site_plan_urls,
                'amenities' => $project->amenities ?? [],
                'page_slug' => $project->is_public ? $project->slug : null,
                'completion_date' => $project->completion_date?->toDateString(),
            ],
            'unit' => [
                'name' => $unit->name,
                'unit_type' => $unit->unit_type,
                'category' => $unit->category,
                'floor' => $unit->floor,
                'area_sqm' => $unit->area_sqm !== null ? (float) $unit->area_sqm : null,
                'floor_plan_url' => $unit->floor_plan_url,
                'highlights' => $unit->buyer_notes,
            ],
            'model' => $model ? ['name' => $model->name, 'specs' => $model->specs, 'images' => $model->images] : null,
            'agent' => $this->agent ? ['name' => $this->agent->name, 'email' => $this->agent->email] : null,
            // The firm that sold it, when it isn't the developer's own team.
            'broker' => $this->broker ? ['name' => $this->broker->name, 'phone' => $this->broker->phone, 'email' => $this->broker->email] : null,
            // The buyer's own checklist. No file links: documents only open from the realty's dashboard.
            'requirements' => $this->requirementList(),
            'details_submitted_at' => $this->details_submitted_at,
            'buyer_contact' => ['email' => $this->buyer_email, 'phone' => $this->buyer_phone],
        ];
    }
}
