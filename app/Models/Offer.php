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
            'project' => ['name' => $this->project->name, 'location' => $this->project->location, 'lat' => $this->project->lat, 'lng' => $this->project->lng, 'description' => $this->project->description, 'cover_url' => $this->project->cover_url, 'completion_date' => $this->project->completion_date?->toDateString()],
            'unit' => ['name' => $this->unit->name, 'unit_type' => $this->unit->unit_type, 'category' => $this->unit->category, 'floor' => $this->unit->floor, 'area_sqm' => $this->unit->area_sqm !== null ? (float) $this->unit->area_sqm : null, 'floor_plan_url' => $this->unit->floor_plan_url, 'notes' => $this->unit->notes],
            'agent' => $this->agent ? ['name' => $this->agent->name, 'email' => $this->agent->email] : null,
        ];
    }
}
