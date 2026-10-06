<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferResponse;
use App\Models\PaymentPlan;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sales offers. Agents see and make their own; staff see everyone's. */
class OfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $offers = $this->visible($request)
            ->with(['project:id,name', 'unit:id,name,unit_type', 'agent:id,name', 'responses'])
            ->latest()
            ->get()
            ->map(fn (Offer $o) => $this->row($o));

        return response()->json($offers);
    }

    /** One offer with everything the agent needs to follow up. Opening it marks its responses as seen. */
    public function show(Request $request, int $id): JsonResponse
    {
        $offer = $this->visible($request)->with(['project', 'unit', 'agent:id,name,email', 'responses'])->findOrFail($id);
        $leads = $offer->responses->sortByDesc('created_at')->values()->map(fn (OfferResponse $r) => $r->toLead());
        $offer->responses()->whereNull('seen_at')->update(['seen_at' => now()]);

        return response()->json($this->row($offer) + [
            'schedule' => $offer->schedule,
            'fee_notes' => $offer->fee_notes,
            'first_viewed_at' => $offer->first_viewed_at,
            'last_viewed_at' => $offer->last_viewed_at,
            'unit_detail' => ['name' => $offer->unit?->name, 'unit_type' => $offer->unit?->unit_type, 'area_sqm' => $offer->unit?->area_sqm !== null ? (float) $offer->unit->area_sqm : null, 'status' => $offer->unit?->status],
            'responses' => $leads,
        ]);
    }

    /** Newest buyer responses across the offers this person can see (Overview panel). */
    public function responses(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = OfferResponse::with(['offer:id,code,buyer_name,unit_id,project_id,agent_id', 'offer.unit:id,name', 'offer.project:id,name'])
            ->where('realty_id', $user->realty_id)
            ->when($user->role === User::ROLE_AGENT, fn ($q) => $q->whereHas('offer', fn ($o) => $o->where('agent_id', $user->id)))
            ->latest()
            ->take(min(max($request->integer('limit', 8), 1), 50))
            ->get()
            ->map(fn (OfferResponse $r) => $r->toLead() + [
                'offer_id' => $r->offer_id,
                'offer_code' => $r->offer?->code,
                'unit' => $r->offer?->unit?->name,
                'project' => $r->offer?->project?->name,
            ]);

        return response()->json($rows);
    }

    /** Offers this user may see: their own for agents, the realty's for staff. */
    private function visible(Request $request)
    {
        $user = $request->user();

        return Offer::where('realty_id', $user->realty_id)
            ->when($user->role === User::ROLE_AGENT, fn ($q) => $q->where('agent_id', $user->id));
    }

    private function row(Offer $o): array
    {
        $latest = $o->responses->sortByDesc('created_at')->first();

        return [
            'id' => $o->id,
            'code' => $o->code,
            'status' => $o->status,
            'buyer_name' => $o->buyer_name,
            'buyer_email' => $o->buyer_email,
            'purchase_date' => $o->purchase_date?->toDateString(),
            'price' => (float) $o->price,
            'views' => $o->views,
            'created_at' => $o->created_at,
            'project' => $o->project?->name,
            'unit' => $o->unit ? trim($o->unit->name.' · '.($o->unit->unit_type ?? ''), ' ·') : null,
            'agent' => $o->agent?->name,
            'url' => Offer::url($o->code),
            'first_viewed_at' => $o->first_viewed_at,
            'last_viewed_at' => $o->last_viewed_at,
            'responses_count' => $o->responses->count(),
            'new_responses' => $o->responses->whereNull('seen_at')->count(),
            'latest_response' => $latest ? ['kind' => $latest->kind, 'label' => OfferResponse::LABELS[$latest->kind] ?? $latest->kind, 'at' => $latest->created_at] : null,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'payment_plan_id' => ['nullable', 'integer'],
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_email' => ['nullable', 'email', 'max:190'],
            'purchase_date' => ['required', 'date'],
        ]);

        $unit = Unit::with('project')->where('realty_id', $user->realty_id)->findOrFail($data['unit_id']);
        if ($unit->price === null) {
            return response()->json(['message' => 'This unit has no price yet, so it cannot be offered.', 'errors' => ['unit_id' => ['This unit has no price yet.']]], 422);
        }
        $plan = null;
        if (! empty($data['payment_plan_id'])) {
            $plan = PaymentPlan::where('realty_id', $user->realty_id)->where('project_id', $unit->project_id)->findOrFail($data['payment_plan_id']);
        }
        $purchase = Carbon::parse($data['purchase_date']);
        $milestones = $plan?->milestones ?? [['label' => 'Full payment', 'percent' => 100, 'days' => 0]];

        $offer = Offer::create([
            'realty_id' => $user->realty_id,
            'project_id' => $unit->project_id,
            'unit_id' => $unit->id,
            'payment_plan_id' => $plan?->id,
            'agent_id' => $user->id,
            'code' => Offer::newCode(),
            'buyer_name' => $data['buyer_name'],
            'buyer_email' => $data['buyer_email'] ?? null,
            'purchase_date' => $purchase->toDateString(),
            'price' => $unit->price,
            'schedule' => Offer::buildSchedule((float) $unit->price, $milestones, $purchase, $unit->project->completion_date),
            'fee_notes' => $unit->project->fee_notes,
        ]);

        return response()->json(['id' => $offer->id, 'code' => $offer->code, 'url' => Offer::url($offer->code)], 201);
    }

    /** Staff may void any offer of theirs; an agent only their own. */
    public function void(Request $request, Offer $offer): JsonResponse
    {
        $user = $request->user();
        abort_unless($offer->realty_id === $user->realty_id && ($user->role === User::ROLE_REALTY || $offer->agent_id === $user->id), 404);
        $offer->update(['status' => 'void']);

        return response()->json(['id' => $offer->id, 'status' => $offer->status]);
    }
}
