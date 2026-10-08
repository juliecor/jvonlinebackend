<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Mail\AccreditationApprovedMail;
use App\Mail\AccreditationInviteMail;
use App\Models\AccreditationDocument;
use App\Models\Realty;
use App\Models\RealtyAccreditation;
use App\Models\User;
use App\Support\QuietMail;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Team > Realties, for the developer's admins (Johndorf): invite a realty by email, read the
 * accreditation form it sends back, accept it (which makes the broker realty and mails its
 * login) or turn it down.
 */
class RealtyAccreditationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $developer = $request->user()->realty;
        $forms = fn (string $status) => $developer->accreditations()->where('status', $status);

        $brokers = $developer->brokers()->withCount(['agents', 'brokerOffers as offers_count'])->orderBy('name')->get();
        $accreditationIds = RealtyAccreditation::whereIn('realty_id', $brokers->pluck('id'))->pluck('id', 'realty_id');

        return response()->json([
            'pending' => $forms(RealtyAccreditation::STATUS_SUBMITTED)->withCount('documents')->orderBy('submitted_at')->get()->map(fn (RealtyAccreditation $a) => [
                'id' => $a->id,
                'firm_name' => $a->firm_name,
                'business_type' => $a->business_type,
                'representative_name' => $a->representative_name,
                'email' => $a->login_email,
                'submitted_at' => $a->submitted_at,
                'documents_count' => $a->documents_count,
            ]),
            'brokers' => $brokers->map(fn (Realty $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'contact_name' => $r->contact_name,
                'email' => $r->email,
                'phone' => $r->phone,
                'agents_count' => $r->agents_count,
                'offers_count' => $r->offers_count,
                'accredited_at' => $r->registered_at,
                'accreditation_id' => $accreditationIds[$r->id] ?? null,
            ]),
            'invited' => $forms(RealtyAccreditation::STATUS_INVITED)->with('inviter:id,name')->latest()->get()->map(fn (RealtyAccreditation $a) => [
                'id' => $a->id,
                'email' => $a->email,
                'invited_at' => $a->created_at,
                'expires_at' => $a->expires_at,
                'expired' => $a->expires_at->isPast(),
                'invited_by' => $a->inviter?->name,
            ]),
            'rejected' => $forms(RealtyAccreditation::STATUS_REJECTED)->latest('reviewed_at')->get()->map(fn (RealtyAccreditation $a) => [
                'id' => $a->id,
                'firm_name' => $a->firm_name,
                'email' => $a->login_email ?? $a->email,
                'review_note' => $a->review_note,
                'reviewed_at' => $a->reviewed_at,
            ]),
        ]);
    }

    /** Invite a realty by email: it gets a link to the accreditation form. */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $developer = $request->user()->realty;
        $email = mb_strtolower($data['email']);

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'There is already an account with this email.']);
        }
        $open = $developer->accreditations()->where('email', $email)->whereIn('status', [RealtyAccreditation::STATUS_INVITED, RealtyAccreditation::STATUS_SUBMITTED])->get();
        if ($open->contains(fn (RealtyAccreditation $a) => $a->status === RealtyAccreditation::STATUS_SUBMITTED)) {
            throw ValidationException::withMessages(['email' => 'This email already sent in its form. Review it under Pending review.']);
        }
        if ($open->contains(fn (RealtyAccreditation $a) => $a->expires_at->isFuture())) {
            throw ValidationException::withMessages(['email' => 'An invite to this email is still open. Resend it instead.']);
        }

        [$accreditation, $token] = DB::transaction(function () use ($developer, $open, $email, $request) {
            $open->each->delete(); // only expired, unfilled ones are left here: the new link replaces them

            return RealtyAccreditation::issue($developer, $email, $request->user());
        });

        return response()->json($this->sent($accreditation, $token), 201);
    }

    /** A fresh link for an invite that wasn't filled in (the old one stops working). */
    public function resend(Request $request, int $accreditation): JsonResponse
    {
        $form = $this->own($request, $accreditation);
        abort_unless($form->status === RealtyAccreditation::STATUS_INVITED, 409, 'This form was already sent in.');

        return response()->json($this->sent($form, $form->refresh()));
    }

    /** One submitted form in full, for the review page. */
    public function show(Request $request, int $accreditation): JsonResponse
    {
        $form = $this->own($request, $accreditation);
        abort_if($form->status === RealtyAccreditation::STATUS_INVITED, 404, 'This invite has not been filled in yet.');
        $form->load(['documents', 'reviewer:id,name', 'realty:id,name,slug']);
        $admin = $form->realty ? User::where('realty_id', $form->realty_id)->where('email', $form->login_email)->first() : null;

        return response()->json([
            'id' => $form->id,
            'status' => $form->status,
            'invited_email' => $form->email,
            'submitted_at' => $form->submitted_at,
            'details' => collect($form->only([
                'business_type', 'firm_name', 'residential_address', 'tin_company', 'tin_personal', 'prc_number', 'prc_valid_until', 'hlurb_number', 'hlurb_issued_at',
                'representative_name', 'place_of_birth', 'date_of_birth', 'citizenship', 'gender', 'civil_status', 'landline', 'mobile', 'login_email', 'facebook',
                'years_in_real_estate', 'years_firm_operating', 'salespersons',
            ]))->map(fn ($value) => $value instanceof CarbonInterface ? $value->toDateString() : $value),
            'documents' => $form->documents->map(fn (AccreditationDocument $d) => [
                'id' => $d->id,
                'kind' => $d->kind,
                'label' => AccreditationDocument::KINDS[$d->kind]['label'] ?? $d->kind,
                'original_name' => $d->original_name,
                'mime' => $d->mime,
                'size' => $d->size,
            ]),
            'reviewed_by' => $form->reviewer?->name,
            'reviewed_at' => $form->reviewed_at,
            'review_note' => $form->review_note,
            'realty' => $form->realty ? ['id' => $form->realty->id, 'name' => $form->realty->name, 'slug' => $form->realty->slug, 'login_url' => $form->realty->loginUrl()] : null,
            // Still holding the mailed temporary password: "Resend login details" is available.
            'login_pending' => (bool) $admin?->must_change_password,
        ]);
    }

    /** Opens one attached file (the dashboard streams it through to the browser). */
    public function document(Request $request, int $accreditation, int $document)
    {
        $doc = $this->own($request, $accreditation)->documents()->whereKey($document)->firstOrFail();
        $disk = Storage::disk(AccreditationDocument::disk());
        abort_unless($disk->exists($doc->path), 404, 'The file is missing from storage.');

        return $disk->response($doc->path, $doc->original_name, [
            'Content-Type' => $doc->mime,
            // Never let an uploaded file run as a page.
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /**
     * Accept: the realty becomes an accredited broker under this developer and gets an admin
     * login with a temporary password it must replace. The email goes out after the commit;
     * if it can't be sent the staff get the password to pass on by hand.
     */
    public function approve(Request $request, int $accreditation): JsonResponse
    {
        $reviewer = $request->user();
        $developer = $reviewer->realty;
        $found = $this->own($request, $accreditation);

        [$realty, $admin, $password] = DB::transaction(function () use ($found, $reviewer, $developer) {
            $form = RealtyAccreditation::whereKey($found->id)->lockForUpdate()->firstOrFail();
            abort_unless($form->status === RealtyAccreditation::STATUS_SUBMITTED, 409, 'This form is not waiting for review.');
            if (User::where('email', $form->login_email)->exists()) {
                throw ValidationException::withMessages(['login_email' => 'There is already an account with '.$form->login_email.'. Ask the realty for another email.']);
            }

            $realty = Realty::create([
                'name' => $form->firm_name,
                'slug' => Realty::slugFor($form->firm_name),
                'kind' => Realty::KIND_BROKER,
                'developer_id' => $developer->id,
                'email' => $form->login_email,
                'contact_name' => $form->representative_name,
                'phone' => $form->mobile,
                'address' => $form->residential_address,
                // An accredited realty works inside Johndorf's brand.
                'logo_path' => $developer->logo_path,
                'accent_color' => $developer->accent_color,
                'status' => Realty::STATUS_ACTIVE,
                'registered_at' => now(),
            ]);
            $password = Str::password(12, symbols: false, spaces: false);
            $admin = $this->makeAdmin($realty, $form, $password);
            $form->update([
                'status' => RealtyAccreditation::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => null,
                'realty_id' => $realty->id,
            ]);

            return [$realty, $admin, $password];
        });

        return response()->json($this->loginSent($realty->setRelation('developer', $developer), $admin, $password), 201);
    }

    /** Turn it down, with a reason the staff can look back on. Nothing is emailed. */
    public function reject(Request $request, int $accreditation): JsonResponse
    {
        $found = $this->own($request, $accreditation);
        $data = $request->validate(
            ['note' => ['required', 'string', 'max:500']],
            ['note.required' => 'Say why, e.g. "The PRC registration is unreadable".'],
        );

        DB::transaction(function () use ($found, $data, $request) {
            $form = RealtyAccreditation::whereKey($found->id)->lockForUpdate()->firstOrFail();
            abort_unless($form->status === RealtyAccreditation::STATUS_SUBMITTED, 409, 'This form is not waiting for review.');
            $form->update([
                'status' => RealtyAccreditation::STATUS_REJECTED,
                'review_note' => $data['note'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return response()->json(['id' => $found->id, 'status' => RealtyAccreditation::STATUS_REJECTED]);
    }

    /** A new temporary password, for a realty that hasn't signed in yet (the mail got lost). */
    public function resendLogin(Request $request, int $accreditation): JsonResponse
    {
        $form = $this->own($request, $accreditation);
        abort_unless($form->status === RealtyAccreditation::STATUS_APPROVED, 409, 'This form was not accepted.');
        $admin = User::where('realty_id', $form->realty_id)->where('email', $form->login_email)->first();
        abort_unless($admin?->must_change_password, 409, 'They have signed in and chosen their own password already.');

        $password = Str::password(12, symbols: false, spaces: false);
        $admin->forceFill(['password' => $password])->save();
        $admin->tokens()->delete();

        return response()->json($this->loginSent($form->realty->setRelation('developer', $request->user()->realty), $admin, $password));
    }

    private function own(Request $request, int $id): RealtyAccreditation
    {
        return RealtyAccreditation::where('developer_id', $request->user()->realty_id)->findOrFail($id);
    }

    private function makeAdmin(Realty $realty, RealtyAccreditation $form, string $password): User
    {
        $admin = new User([
            'name' => $form->representative_name,
            'email' => $form->login_email,
            'password' => $password,
            'role' => User::ROLE_REALTY,
            'realty_id' => $realty->id,
            'phone' => $form->mobile,
        ]);
        // Set by the server only (like is_superadmin): never from a request.
        $admin->forceFill(['must_change_password' => true])->save();

        return $admin;
    }

    /**
     * Emails the invite and returns the link as well, so the staff can pass it on by hand.
     *
     * @return array<string, mixed>
     */
    private function sent(RealtyAccreditation $form, string $token): array
    {
        $url = RealtyAccreditation::url($token);
        $emailed = QuietMail::send($form->email, new AccreditationInviteMail($form->loadMissing('developer'), $url), "invite {$form->id}");

        return ['id' => $form->id, 'email' => $form->email, 'expires_at' => $form->expires_at, 'emailed' => $emailed, 'accreditation_url' => $url];
    }

    /**
     * @return array<string, mixed>
     */
    private function loginSent(Realty $realty, User $admin, string $password): array
    {
        $loginUrl = $realty->loginUrl();
        $emailed = QuietMail::send($admin->email, new AccreditationApprovedMail($realty, $admin, $password, $loginUrl), "login {$realty->slug}");

        return [
            'realty' => $realty->only(['id', 'name', 'slug']),
            'username' => $admin->email,
            'login_url' => $loginUrl,
            'emailed' => $emailed,
            // Only when the mail couldn't be sent, so there is always a way to hand the login over.
            'temporary_password' => $emailed ? null : $password,
        ];
    }
}
