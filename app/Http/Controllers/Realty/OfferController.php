<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Offer;
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
        $offers = Offer::with(['project:id,name', 'unit:id,name,unit_type', 'agent:id,name'])
            ->where('realty_id', $user->realty_id)
            ->when($user->role === User::ROLE_AGENT, fn ($q) => $q->where('agent_id', $user->id))
            ->latest()
            ->get()
            ->map(fn (Offer $o) => [
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
            ]);

        return response()->json($offers);
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
