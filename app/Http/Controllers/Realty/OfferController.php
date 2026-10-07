<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Mail\OfferToBuyerMail;
use App\Mail\RequirementsReminderMail;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\PaymentPlan;
use App\Models\RequirementType;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Sales offers. Agents see and make their own; staff see everyone's. */
class OfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $types = RequirementType::where('realty_id', $user->realty_id)->orderBy('sort')->orderBy('id')->get();
        $offers = $this->visible($request)
            ->with(['project:id,name', 'unit:id,name,unit_type', 'agent:id,name', 'responses', 'documents'])
            ->latest()
            ->get()
            ->map(fn (Offer $o) => $this->row($o) + ['requirements' => $o->requirementSummary($types)]);

        return response()->json($offers);
    }

    /** One offer with everything the agent needs to follow up. Opening it marks its responses as seen. */
    public function show(Request $request, int $id): JsonResponse
    {
        $offer = $this->visible($request)->with(['project', 'unit', 'agent:id,name,email', 'responses', 'documents.reviewer:id,name'])->findOrFail($id);
        $leads = $offer->responses->sortByDesc('created_at')->values()->map(fn (OfferResponse $r) => $r->toLead());
        $offer->responses()->whereNull('seen_at')->update(['seen_at' => now()]);

        return response()->json($this->row($offer) + [
            'schedule' => $offer->schedule,
            'fee_notes' => $offer->fee_notes,
            'first_viewed_at' => $offer->first_viewed_at,
            'last_viewed_at' => $offer->last_viewed_at,
            'unit_detail' => ['name' => $offer->unit?->name, 'unit_type' => $offer->unit?->unit_type, 'area_sqm' => $offer->unit?->area_sqm !== null ? (float) $offer->unit->area_sqm : null, 'status' => $offer->unit?->status],
            'responses' => $leads,
            'buyer_phone' => $offer->buyer_phone,
            'buyer_details' => $offer->buyer_details ? collect($offer->buyer_details)->except('ip')->all() : null,
            'details_submitted_at' => $offer->details_submitted_at,
            'requirements' => $offer->requirementList(true),
            'requirements_summary' => $offer->requirementSummary(),
            'offer_emailed_at' => $offer->offer_emailed_at,
            'last_reminded_at' => $offer->last_reminded_at,
            'reminders_sent' => $offer->reminders_sent,
            'buyer_email_for_mail' => $offer->buyerEmail(),
        ]);
    }

    /** Opens one of the buyer's files (the dashboard streams it through to the browser). */
    public function document(Request $request, int $id, int $document)
    {
        $offer = $this->visible($request)->findOrFail($id);
        $doc = $offer->documents()->whereKey($document)->firstOrFail();
        $disk = Storage::disk(OfferDocument::disk());
        abort_unless($disk->exists($doc->path), 404, 'The file is missing from storage.');

        return $disk->response($doc->path, $doc->original_name, [
            'Content-Type' => $doc->mime,
            // Never let an uploaded file run as a page.
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /** Approve a file, or send it back with a reason the buyer will see. */
    public function review(Request $request, int $id, int $document): JsonResponse
    {
        $offer = $this->visible($request)->findOrFail($id);
        $doc = $offer->documents()->whereKey($document)->firstOrFail();
        $data = $request->validate([
            'status' => ['required', Rule::in(OfferDocument::STATUSES)],
            'note' => ['nullable', 'string', 'max:300', Rule::requiredIf($request->input('status') === 'rejected')],
        ], ['note.required' => 'Tell the buyer what to fix, e.g. "The photo is blurry".']);
        $doc->update([
            'status' => $data['status'],
            'note' => $data['status'] === 'rejected' ? $data['note'] : null,
            'reviewed_by' => $data['status'] === 'pending' ? null : $request->user()->id,
            'reviewed_at' => $data['status'] === 'pending' ? null : now(),
        ]);

        return response()->json(['id' => $doc->id, 'status' => $doc->status]);
    }

    /** Email the buyer what's still missing, with the link straight to it. At most every 10 minutes. */
    public function remind(Request $request, int $id): JsonResponse
    {
        $offer = $this->visible($request)->with(['unit', 'project', 'realty', 'agent', 'documents'])->findOrFail($id);
        abort_unless($offer->status === 'active', 422, 'This offer is void.');
        $to = $offer->buyerEmail();
        abort_unless($to, 422, "There's no email for this buyer. Copy the link and send it by Viber or text instead.");
        if ($offer->last_reminded_at && $offer->last_reminded_at->gt(now()->subMinutes(10))) {
            abort(429, 'A reminder went out '.$offer->last_reminded_at->diffForHumans().'. Wait a few minutes before sending another.');
        }
        $todo = collect($offer->requirementList())->where('needed', 'required')->whereIn('state', ['missing', 'rejected'])->values()->all();
        $needsDetails = $offer->details_submitted_at === null;
        abort_if(! $todo && ! $needsDetails, 422, 'Nothing is missing: every required item is in.');

        $this->mail($to, new RequirementsReminderMail($offer, $todo, $needsDetails), $offer);
        $offer->forceFill(['last_reminded_at' => now(), 'reminders_sent' => $offer->reminders_sent + 1])->save();

        return response()->json(['sent_to' => $to, 'last_reminded_at' => $offer->last_reminded_at, 'reminders_sent' => $offer->reminders_sent]);
    }

    /** Email the offer link to the buyer (again). */
    public function send(Request $request, int $id): JsonResponse
    {
        $offer = $this->visible($request)->with(['unit', 'project', 'realty', 'agent'])->findOrFail($id);
        abort_unless($offer->status === 'active', 422, 'This offer is void.');
        $to = $offer->buyerEmail();
        abort_unless($to, 422, "There's no email for this buyer.");
        $this->mail($to, new OfferToBuyerMail($offer), $offer);
        $offer->forceFill(['offer_emailed_at' => now()])->save();

        return response()->json(['sent_to' => $to, 'offer_emailed_at' => $offer->offer_emailed_at]);
    }

    /** Sends, or answers with a message the agent can act on when the mail server refuses. */
    private function mail(string $to, Mailable $mail, Offer $offer): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            Log::warning('Buyer mail failed', ['offer' => $offer->code, 'to' => $to, 'error' => $e->getMessage()]);
            abort(502, "The email to {$to} couldn't be sent. Check the address, or copy the link and send it by Viber or text.");
        }
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
            'buyer_email' => ['nullable', 'email', 'max:190', Rule::requiredIf($request->boolean('email_buyer'))],
            'buyer_phone' => ['nullable', 'string', 'max:40'],
            'purchase_date' => ['required', 'date'],
            'email_buyer' => ['boolean'],
        ], ['buyer_email.required' => "Enter the buyer's email to send them the offer."]);

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
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'purchase_date' => $purchase->toDateString(),
            'price' => $unit->price,
            'schedule' => Offer::buildSchedule((float) $unit->price, $milestones, $purchase, $unit->project->completion_date),
            'fee_notes' => $unit->project->fee_notes,
        ]);

        $emailed = null;
        if ($request->boolean('email_buyer') && $offer->buyer_email) {
            try {
                Mail::to($offer->buyer_email)->send(new OfferToBuyerMail($offer));
                $offer->forceFill(['offer_emailed_at' => now()])->save();
                $emailed = $offer->buyer_email;
            } catch (\Throwable $e) {
                // The offer exists either way; the agent can resend from its page.
                Log::warning('Offer email to buyer failed', ['offer' => $offer->code, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['id' => $offer->id, 'code' => $offer->code, 'url' => Offer::url($offer->code), 'emailed_to' => $emailed], 201);
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
