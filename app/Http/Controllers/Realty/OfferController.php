<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Mail\ApprovalRequestMail;
use App\Mail\ApprovalResultMail;
use App\Mail\OfferToBuyerMail;
use App\Mail\RequirementsReminderMail;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\PaymentPlan;
use App\Models\RequirementType;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Support\Milestones;
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
            ->with(['project:id,name,cover_path,hero_paths', 'unit:id,project_id,name,unit_type,status,status_offer_id,floor_plan_path', 'agent:id,name', 'responses', 'documents'])
            ->latest()
            ->get();
        // One query for every house model, so each row can show its picture.
        $models = UnitType::whereIn('project_id', $offers->pluck('project_id')->unique())->get();
        $offers = $offers->map(fn (Offer $o) => $this->row($o) + ['requirements' => $o->requirementSummary($types), 'photo' => $o->photo($models)]);

        return response()->json($offers);
    }

    /** One offer with everything the agent needs to follow up. Opening it marks its responses as seen. */
    public function show(Request $request, int $id): JsonResponse
    {
        $offer = $this->visible($request)->with(['project', 'unit', 'agent:id,name,email', 'responses', 'documents.reviewer:id,name', 'approver:id,name', 'paymentPlan:id,name'])->findOrFail($id);
        $leads = $offer->responses->sortByDesc('created_at')->values()->map(fn (OfferResponse $r) => $r->toLead());
        $offer->responses()->whereNull('seen_at')->update(['seen_at' => now()]);

        return response()->json($this->row($offer) + [
            'schedule' => $offer->schedule,
            'fee_notes' => $offer->fee_notes,
            'first_viewed_at' => $offer->first_viewed_at,
            'last_viewed_at' => $offer->last_viewed_at,
            'unit_hold' => $offer->unit ? $this->unitHold($offer, $request->user()) : null,
            'photo' => $offer->photo(),
            'unit_detail' => ['name' => $offer->unit?->name, 'unit_type' => $offer->unit?->unit_type, 'area_sqm' => $offer->unit?->area_sqm !== null ? (float) $offer->unit->area_sqm : null, 'status' => $offer->unit?->status],
            'responses' => $leads,
            'buyer_phone' => $offer->buyer_phone,
            // The buyer's login: the agent can see it again (and resend it) when the buyer forgets.
            'access_username' => $offer->access_username,
            'access_password' => $offer->isLocked() ? $offer->access_password : null,
            'buyer_details' => $offer->buyer_details ? collect($offer->buyer_details)->except('ip')->all() : null,
            'details_submitted_at' => $offer->details_submitted_at,
            'requirements' => $offer->requirementList(true),
            'requirements_summary' => $offer->requirementSummary(),
            'offer_emailed_at' => $offer->offer_emailed_at,
            'last_reminded_at' => $offer->last_reminded_at,
            'reminders_sent' => $offer->reminders_sent,
            'buyer_email_for_mail' => $offer->buyerEmail(),
            'custom_milestones' => $offer->custom_milestones,
            'approval_reason' => $offer->approval_reason,
            'approved_by' => $offer->approver?->name,
            'approved_at' => $offer->approved_at,
            'plan_name' => $offer->paymentPlan?->name,
            // The project's official plans on the same price and date, so the approver can compare.
            'official_plans' => $offer->custom_milestones === null ? [] : PaymentPlan::where('project_id', $offer->project_id)->orderBy('id')->get()
                ->map(fn (PaymentPlan $p) => ['name' => $p->name, 'schedule' => Offer::buildSchedule((float) $offer->price, $p->milestones, $offer->purchase_date, $offer->project?->completion_date)])->values(),
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
        abort_if($offer->awaitingApproval(), 422, 'The custom terms are not approved yet, so the buyer cannot open the offer.');
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
        abort_if($offer->awaitingApproval(), 422, 'The custom terms are not approved yet, so the buyer cannot open the offer.');
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
            'locked' => $o->isLocked(),
            'purchase_date' => $o->purchase_date?->toDateString(),
            'price' => (float) $o->price,
            'views' => $o->views,
            'created_at' => $o->created_at,
            'project' => $o->project?->name,
            // "Unit 415 · 1 Bedroom"; just the name when the type says the same thing.
            'unit' => $o->unit ? implode(' · ', array_unique(array_filter([$o->unit->name, $o->unit->unit_type]))) : null,
            // Reserved or sold through this offer.
            'unit_status' => $o->unit && $o->unit->status_offer_id === $o->id ? $o->unit->status : null,
            'agent' => $o->agent?->name,
            'agent_id' => $o->agent_id,
            'url' => Offer::url($o->code),
            'first_viewed_at' => $o->first_viewed_at,
            'last_viewed_at' => $o->last_viewed_at,
            'responses_count' => $o->responses->count(),
            'new_responses' => $o->responses->whereNull('seen_at')->count(),
            'latest_response' => $latest ? ['kind' => $latest->kind, 'label' => OfferResponse::LABELS[$latest->kind] ?? $latest->kind, 'at' => $latest->created_at] : null,
            'custom' => $o->custom_milestones !== null,
            'approval_status' => $o->approval_status,
            'approval_note' => $o->approval_note,
        ];
    }

    /**
     * A new offer: an official plan of the project (ready at once), or the
     * agent's custom terms, which wait for a realty admin's approval. An
     * admin's own custom terms count as approved.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $custom = $request->boolean('custom');
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'payment_plan_id' => ['nullable', 'integer'],
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_email' => ['nullable', 'email', 'max:190', Rule::requiredIf($request->boolean('email_buyer'))],
            'buyer_phone' => ['nullable', 'string', 'max:40'],
            'purchase_date' => ['required', 'date'],
            'email_buyer' => ['boolean'],
            'custom' => ['boolean'],
            'approval_reason' => ['nullable', 'string', 'max:500'],
        ] + self::loginRules() + ($custom ? Milestones::rules('custom_milestones') : []), ['buyer_email.required' => "Enter the buyer's email to send them the offer."] + self::loginMessages());

        $unit = Unit::with('project')->where('realty_id', $user->realty_id)->findOrFail($data['unit_id']);
        if ($unit->price === null) {
            return response()->json(['message' => 'This unit has no price yet, so it cannot be offered.', 'errors' => ['unit_id' => ['This unit has no price yet.']]], 422);
        }
        $plan = null;
        if ($custom) {
            $milestones = Milestones::normalize($data['custom_milestones'], 'custom_milestones');
        } else {
            if (! empty($data['payment_plan_id'])) {
                $plan = PaymentPlan::where('realty_id', $user->realty_id)->where('project_id', $unit->project_id)->findOrFail($data['payment_plan_id']);
            }
            $milestones = $plan?->milestones ?? [['label' => 'Full payment', 'percent' => 100, 'days' => 0]];
        }
        $staff = $user->role === User::ROLE_REALTY;
        $purchase = Carbon::parse($data['purchase_date']);

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
            'access_username' => trim($data['access_username']),
            'access_password' => $data['access_password'],
            'purchase_date' => $purchase->toDateString(),
            'price' => $unit->price,
            'schedule' => Offer::buildSchedule((float) $unit->price, $milestones, $purchase, $unit->project->completion_date),
            'fee_notes' => $unit->project->fee_notes,
            'custom_milestones' => $custom ? $milestones : null,
            'approval_status' => $custom ? ($staff ? 'approved' : 'pending') : null,
            'approval_reason' => $custom ? ($data['approval_reason'] ?? null) : null,
            'approved_by' => $custom && $staff ? $user->id : null,
            'approved_at' => $custom && $staff ? now() : null,
            // Waiting for approval: the offer goes to the buyer once it's approved.
            'email_on_approval' => $custom && ! $staff && $request->boolean('email_buyer'),
        ]);

        $emailed = null;
        if ($offer->isLive() && $request->boolean('email_buyer') && $offer->buyer_email) {
            $emailed = $this->emailBuyerQuietly($offer);
        }
        if ($offer->approval_status === 'pending') {
            $this->askForApproval($offer);
        }

        return response()->json(['id' => $offer->id, 'code' => $offer->code, 'url' => Offer::url($offer->code), 'emailed_to' => $emailed, 'approval_status' => $offer->approval_status], 201);
    }

    /**
     * A realty admin marks the offer's unit reserved or sold to this buyer (or
     * available again). A unit held through another offer has to be let go there first.
     */
    public function unitStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === User::ROLE_REALTY, 403, "Only your realty's admins can change a unit's status.");
        $offer = $this->visible($request)->with('unit.statusOffer:id,code,buyer_name')->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(Unit::STATUSES)]]);
        $unit = $offer->unit;
        abort_unless($unit !== null, 404, 'This offer has no unit.');

        $other = $unit->status !== 'available' && $unit->status_offer_id && $unit->status_offer_id !== $offer->id ? $unit->statusOffer : null;
        if ($other) {
            return response()->json(['message' => "This unit is already {$unit->status} to {$other->buyer_name} (offer {$other->code}). Set it back to available on that offer first."], 409);
        }
        abort_unless($offer->status === 'active' || $data['status'] === 'available', 422, 'This offer is void.');

        $unit->update([
            'status' => $data['status'],
            'status_offer_id' => $data['status'] === 'available' ? null : $offer->id,
            'status_by_id' => $user->id,
            'status_at' => now(),
        ]);

        return response()->json($this->unitHold($offer->fresh('unit'), $user));
    }

    /**
     * The offer's unit: its status, whether this offer holds it, and who has it.
     *
     * @return array{status: string, this_offer: bool, detail: array<string, mixed>|null}
     */
    private function unitHold(Offer $offer, User $user): array
    {
        $unit = $offer->unit->loadMissing(['statusOffer:id,code,buyer_name,agent_id', 'statusOffer.agent:id,name', 'statusBy:id,name']);
        $mine = $unit->status_offer_id === $offer->id;

        return ['status' => $unit->status, 'this_offer' => $mine, 'detail' => $unit->statusDetail($mine || $user->role === User::ROLE_REALTY)];
    }

    /** Set or change the buyer's username and password. A new password signs the buyer out of the old one. */
    public function login(Request $request, int $id): JsonResponse
    {
        $offer = $this->visible($request)->findOrFail($id);
        $data = $request->validate(self::loginRules(), self::loginMessages());
        $offer->update(['access_username' => trim($data['access_username']), 'access_password' => $data['access_password']]);

        return response()->json(['access_username' => $offer->access_username, 'access_password' => $offer->access_password, 'locked' => true]);
    }

    /** @return array<string, array<int, string>> */
    private static function loginRules(): array
    {
        return [
            'access_username' => ['required', 'string', 'min:2', 'max:60'],
            'access_password' => ['required', 'string', 'min:6', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    private static function loginMessages(): array
    {
        return [
            'access_username.required' => 'Give the buyer a username to open the offer with.',
            'access_password.required' => 'Give the buyer a password to open the offer with.',
            'access_password.min' => 'The password needs at least 6 characters.',
        ];
    }

    /** A realty admin approves custom terms (the buyer can open the offer), or sends them back with a note. */
    public function approval(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === User::ROLE_REALTY, 403, 'Only your realty\'s admins can approve terms.');
        $offer = $this->visible($request)->with(['unit', 'project', 'realty', 'agent'])->findOrFail($id);
        abort_unless($offer->approval_status === 'pending', 422, 'This offer isn\'t waiting for approval.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('decision') === 'reject')],
            'save_as_plan' => ['boolean'],
            'plan_name' => ['nullable', 'string', 'max:120', Rule::requiredIf($request->boolean('save_as_plan') && $request->input('decision') === 'approve')],
        ], ['note.required' => 'Tell the agent what to change, e.g. "Equity can be 24 months at most".', 'plan_name.required' => 'Give the new plan a name.']);

        $approve = $data['decision'] === 'approve';
        $offer->update([
            'approval_status' => $approve ? 'approved' : 'rejected',
            'approval_note' => $data['note'] ?? null,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $plan = null;
        if ($approve && $request->boolean('save_as_plan')) {
            $plan = PaymentPlan::create(['realty_id' => $offer->realty_id, 'project_id' => $offer->project_id, 'name' => $data['plan_name'], 'milestones' => $offer->custom_milestones]);
        }
        $emailed = null;
        if ($approve && $offer->email_on_approval && $offer->buyerEmail() && $offer->status === 'active') {
            $emailed = $this->emailBuyerQuietly($offer);
        }
        if ($offer->agent && $offer->agent->id !== $user->id) {
            $this->notify($offer->agent->email, new ApprovalResultMail($offer->load('approver'), $this->dashboardUrl($offer), $emailed), $offer);
        }

        return response()->json(['approval_status' => $offer->approval_status, 'emailed_to' => $emailed, 'plan_id' => $plan?->id]);
    }

    /** The agent changes custom terms (after they were sent back, or while still waiting) and asks again. */
    public function terms(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $offer = $this->visible($request)->with(['unit.project', 'project', 'realty', 'agent'])->findOrFail($id);
        abort_unless($offer->status === 'active' && $offer->custom_milestones !== null, 422, 'Only an active offer with custom terms can be changed.');
        abort_unless($offer->approval_status !== 'approved' || $user->role === User::ROLE_REALTY, 422, 'These terms are approved already. Make a new offer to change them.');
        $data = $request->validate(['approval_reason' => ['nullable', 'string', 'max:500']] + Milestones::rules('custom_milestones'));
        $milestones = Milestones::normalize($data['custom_milestones'], 'custom_milestones');
        $staff = $user->role === User::ROLE_REALTY;

        $offer->update([
            'custom_milestones' => $milestones,
            'schedule' => Offer::buildSchedule((float) $offer->price, $milestones, $offer->purchase_date, $offer->project->completion_date),
            'approval_status' => $staff ? 'approved' : 'pending',
            'approval_reason' => $data['approval_reason'] ?? $offer->approval_reason,
            'approval_note' => null,
            'approved_by' => $staff ? $user->id : null,
            'approved_at' => $staff ? now() : null,
        ]);
        if (! $staff) {
            $this->askForApproval($offer);
        }

        return response()->json(['approval_status' => $offer->approval_status]);
    }

    /** Email every admin of the realty (except whoever made it) that terms wait for them. */
    private function askForApproval(Offer $offer): void
    {
        $offer->loadMissing(['unit', 'project', 'agent']);
        $admins = User::where('realty_id', $offer->realty_id)->where('role', User::ROLE_REALTY)->where('id', '!=', $offer->agent_id)->pluck('email');
        foreach ($admins as $email) {
            $this->notify($email, new ApprovalRequestMail($offer, $this->dashboardUrl($offer)), $offer);
        }
    }

    private function emailBuyerQuietly(Offer $offer): ?string
    {
        $to = $offer->buyerEmail();
        try {
            Mail::to($to)->send(new OfferToBuyerMail($offer));
            $offer->forceFill(['offer_emailed_at' => now()])->save();

            return $to;
        } catch (\Throwable $e) {
            // The offer exists either way; the agent can resend from its page.
            Log::warning('Offer email to buyer failed', ['offer' => $offer->code, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Notifications to the realty's own people: a mail hiccup is logged, never shown as a failure. */
    private function notify(string $to, Mailable $mail, Offer $offer): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            Log::warning('Dashboard notification mail failed', ['offer' => $offer->code, 'to' => $to, 'error' => $e->getMessage()]);
        }
    }

    private function dashboardUrl(Offer $offer): string
    {
        return rtrim(config('app.frontend_url'), '/')."/{$offer->realty->slug}/dashboard/offers/{$offer->id}";
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
