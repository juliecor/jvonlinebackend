<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One unit + one payment plan, prepared by an agent for one buyer. The code is the public link. */
#[Fillable(['realty_id', 'project_id', 'unit_id', 'payment_plan_id', 'agent_id', 'code', 'buyer_name', 'buyer_email', 'purchase_date', 'price', 'schedule', 'fee_notes', 'status'])]
class Offer extends Model
{
    protected function casts(): array
    {
        return ['purchase_date' => 'date:Y-m-d', 'price' => 'decimal:2', 'schedule' => 'array'];
    }

    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
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

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
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
     * Turn a plan's milestones into dated amounts for this price. The last
     * milestone takes the rounding so the schedule always adds up to the price.
     *
     * @param  array<int, array{label: string, percent: float|int|string, days: int|null}>  $milestones
     * @return array<int, array{label: string, percent: float, date: string|null, amount: float}>
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
            $date = $m['days'] === null || $m['days'] === '' ? $completionDate?->toDateString() : $purchaseDate->copy()->addDays((int) $m['days'])->toDateString();
            $rows[] = ['label' => $m['label'], 'percent' => $percent, 'date' => $date, 'amount' => $amount];
        }

        return $rows;
    }

    /** Everything the buyer's page shows. */
    public function publicArray(): array
    {
        $this->loadMissing(['realty', 'project', 'unit', 'agent']);
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
                'notes' => $unit->notes,
            ],
            'model' => $model ? ['name' => $model->name, 'specs' => $model->specs, 'images' => $model->images] : null,
            'agent' => $this->agent ? ['name' => $this->agent->name, 'email' => $this->agent->email] : null,
        ];
    }
}
