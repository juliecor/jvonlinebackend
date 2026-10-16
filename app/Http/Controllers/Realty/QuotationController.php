<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\PaymentPlan;
use App\Models\Project;
use App\Models\Unit;
use App\Support\Milestones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A quotation (sample computation) to hand a buyer: a unit, or a price in a project, with one of the
 * project's payment plans or custom terms, worked out by the code that builds offers. Always at today's
 * price and plan and from today (or a later date given), so one opened from an old chat is still right.
 * Read-only: nothing is saved.
 */
class QuotationController extends Controller
{
    /** How a lot's tags read on the quotation. */
    private const TAGS = ['corner' => 'Corner lot', 'park' => 'Near the park', 'end' => 'End unit'];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'price' => ['nullable', 'numeric', 'gt:0', 'max:10000000000'],
            'plan_id' => ['nullable', 'integer'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'buyer' => ['nullable', 'string', 'max:120'],
        ] + ($request->filled('plan_id') ? [] : Milestones::rules('milestones')));

        $user = $request->user();
        $realty = $user->realty;
        $inventory = $realty->inventoryId();

        $unit = null;
        if (isset($data['unit_id'])) {
            $unit = Unit::where('realty_id', $inventory)->with('project')->find($data['unit_id']);
            abort_unless($unit, 404, 'That unit is no longer listed.');
        }
        $project = $unit?->project;
        if (! $project && isset($data['project_id'])) {
            $project = Project::where('realty_id', $inventory)->find($data['project_id']);
            abort_unless($project, 404, 'That project is no longer listed.');
        }

        $price = $unit ? $unit->offerPrice() : ($data['price'] ?? null);
        abort_unless($price !== null && (float) $price > 0, 422, $unit ? "{$unit->name} has no price yet, so it can't be quoted." : 'Say which unit, or give a price.');

        $plan = null;
        if (isset($data['plan_id'])) {
            $plan = $project ? PaymentPlan::where('project_id', $project->id)->find($data['plan_id']) : null;
            abort_unless($plan, 422, 'That payment plan is no longer offered. Work the payments out again.');
            $milestones = $plan->milestones;
        } else {
            $milestones = Milestones::normalize($data['milestones'], 'milestones');
        }

        $today = now('Asia/Manila')->startOfDay();
        $date = isset($data['purchase_date']) ? Carbon::parse($data['purchase_date'], 'Asia/Manila')->max($today) : $today;
        $schedule = Offer::buildSchedule((float) $price, $milestones, $date, $project?->completion_date);

        return response()->json([
            'realty' => $realty->publicArray() + ['email' => $realty->email, 'phone' => $realty->phone, 'address' => $realty->address],
            'prepared' => [
                'by' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'at' => now()->toIso8601String(),
                // Easy to read back over the phone: the day and the unit (or project).
                'reference' => 'Q-'.now('Asia/Manila')->format('ymd').'-'.($unit?->id ?? $project?->id ?? 0),
            ],
            'buyer' => isset($data['buyer']) ? trim($data['buyer']) : null,
            'unit' => $unit ? [
                'id' => $unit->id,
                'name' => $unit->name,
                'model' => $unit->unit_type,
                'category' => $unit->category,
                'floor' => $unit->floor,
                'lot_area_sqm' => $unit->lot_area_sqm !== null ? (float) $unit->lot_area_sqm : null,
                'floor_area_sqm' => $unit->area_sqm !== null ? (float) $unit->area_sqm : null,
                'features' => array_values(array_filter(array_map(fn ($t) => self::TAGS[$t] ?? null, (array) ($unit->tags ?? [])))),
                'status' => $unit->status,
                'photo' => $unit->photo(null, $project),
            ] : null,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'location' => $project->location,
                'stage' => $project->stage,
                'completion_date' => $project->completion_date?->toDateString(),
                'fee_notes' => $project->fee_notes,
                'photo' => $project->hero_urls[0] ?? $project->cover_url,
            ] : null,
            'price' => (float) $price,
            // A lot without its own price is quoted at its house model's price.
            'price_from_model' => $unit !== null && $unit->price === null,
            'terms' => $plan?->name ?? 'Custom terms',
            'plan_id' => $plan?->id,
            'milestones' => array_values($milestones),
            'purchase_date' => $date->toDateString(),
            'schedule' => collect($schedule)->map(fn (array $row) => [
                'label' => $row['label'],
                'percent' => round((float) $row['percent'], 2),
                'amount' => (float) $row['amount'],
                'date' => $row['date'],
                'months' => $row['months'] ?? null,
                'monthly' => $row['monthly'] ?? null,
                'end_date' => $row['end_date'] ?? null,
                'last_month' => isset($row['installments']) ? end($row['installments'])['amount'] : null,
            ])->all(),
            'total' => round(array_sum(array_column($schedule, 'amount')), 2),
            'can_offer' => $unit !== null && $unit->status === 'available' && $project?->status === 'active',
        ]);
    }
}
