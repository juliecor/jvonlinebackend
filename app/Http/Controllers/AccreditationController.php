<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDocument;
use App\Models\Realty;
use App\Models\RealtyAccreditation;
use App\Support\AccreditationAgreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The accreditation form a realty fills in: from its invite email (jvconline.ph/accreditation/<token>, the token is
 * the credential), or open to anyone on the developer's website (jvconline.ph/accreditation). Nothing here needs a
 * login; the developer's staff review what is sent.
 */
class AccreditationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $accreditation = $this->usable($token);

        return response()->json($this->formFor($accreditation->developer) + [
            'email' => $accreditation->email,
            'expires_at' => $accreditation->expires_at,
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $accreditation = $this->usable($token);
        $agreement = AccreditationAgreement::ready($accreditation->developer);
        $data = $this->validated($request, $agreement);

        DB::transaction(function () use ($request, $accreditation, $agreement, $data) {
            // Two clicks on Submit must not file it twice.
            $form = RealtyAccreditation::whereKey($accreditation->id)->lockForUpdate()->firstOrFail();
            abort_unless($form->isUsable(), 410, 'This form was already submitted.');

            $this->fill($form, $request, $data, $agreement);
        });

        return response()->json(['ok' => true], 201);
    }

    /** The open form on the developer's website: the same questions, without an invite. */
    public function openShow(string $realty): JsonResponse
    {
        return response()->json($this->formFor($this->developer($realty)) + ['email' => null]);
    }

    public function openStore(Request $request, string $realty): JsonResponse
    {
        $developer = $this->developer($realty);
        $agreement = AccreditationAgreement::ready($developer);
        // Honeypot: hidden from people, filled in by bots.
        $data = $this->validated($request, $agreement, ['website' => ['nullable', 'max:0']]);
        $email = mb_strtolower($data['login_email']);

        // One form per email at a time: the staff decide on the one they have before another comes in.
        $waiting = $developer->accreditations()->where('status', RealtyAccreditation::STATUS_SUBMITTED)
            ->where(fn ($q) => $q->where('login_email', $email)->orWhere('email', $email))->exists();
        if ($waiting) {
            throw ValidationException::withMessages(['login_email' => "A form with this email is already waiting for review at {$developer->name}."]);
        }

        DB::transaction(function () use ($request, $developer, $agreement, $data, $email) {
            // An invite to the same email that was never filled in: this form takes its place, so its link stops working.
            $developer->accreditations()->where('status', RealtyAccreditation::STATUS_INVITED)->where('email', $email)->delete();

            $form = $developer->accreditations()->create([
                'source' => RealtyAccreditation::SOURCE_WEBSITE,
                'email' => $email,
                'status' => RealtyAccreditation::STATUS_INVITED,
            ]);
            $this->fill($form, $request, ['login_email' => $email] + $data, $agreement);
        });

        return response()->json(['ok' => true], 201);
    }

    /** What the form shows: the developer's brand, the documents to attach and the agreement, if it is set up. */
    private function formFor(Realty $developer): array
    {
        return [
            'developer' => $developer->publicArray(),
            'documents' => AccreditationDocument::forForm(),
            'civil_statuses' => RealtyAccreditation::CIVIL_STATUSES,
            // The agreement the realty agrees to before sending the form (null until the developer has set it up).
            'agreement' => AccreditationAgreement::ready($developer) ? AccreditationAgreement::draft($developer) + [
                'signed_copy_days' => AccreditationAgreement::settings($developer)['signed_copy_days'],
            ] : null,
        ];
    }

    /**
     * The answers, checked; with the agreement set up, the typed name that signs it.
     *
     * @param  array<string, array<int, mixed>>  $extra
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $agreement, array $extra = []): array
    {
        $data = $request->validate($this->rules($request) + ['privacy' => ['accepted']] + $extra + ($agreement ? [
            'agree' => ['accepted'],
            'agreed_name' => ['required', 'string', 'max:120'],
        ] : []), $this->messages() + [
            'privacy.accepted' => 'Tick the box to agree to the data privacy notice.',
            'agree.accepted' => 'Tick the box to agree to the accreditation agreement.',
            'agreed_name.required' => 'Type your full name to sign the agreement.',
        ]);
        // The typed name is the signature: it has to be the representative's own.
        if ($agreement && mb_strtolower(preg_replace('/\s+/', ' ', trim($data['agreed_name']))) !== mb_strtolower(preg_replace('/\s+/', ' ', trim($data['representative_name'])))) {
            throw ValidationException::withMessages(['agreed_name' => 'Type the representative\'s full name exactly as above: '.trim($data['representative_name']).'.']);
        }

        return $data;
    }

    /** Files the answers and documents on the form: it now waits for the developer's staff. Call inside a transaction. */
    private function fill(RealtyAccreditation $form, Request $request, array $data, bool $agreement): void
    {
        $form->update(collect($data)->except(['documents', 'privacy', 'agree', 'agreed_name', 'website'])->all() + [
            'status' => RealtyAccreditation::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_ip' => $request->ip(),
            // They agreed to the data privacy notice (the form can't be sent without it).
            'privacy_accepted_at' => now(),
        ]);
        // What they agreed to, as it stood today, and the typed signature.
        if ($agreement) {
            $form->update([
                'agreement_version' => AccreditationAgreement::VERSION,
                'agreement' => AccreditationAgreement::snapshot($form->developer, $form),
                'agreed_name' => preg_replace('/\s+/', ' ', trim($data['agreed_name'])),
                'agreed_at' => now(),
                'agreed_ip' => $request->ip(),
            ]);
        }

        foreach (array_keys(AccreditationDocument::KINDS) as $kind) {
            $file = $request->file("documents.{$kind}");
            if ($file === null) {
                continue;
            }
            $form->documents()->create([
                'kind' => $kind,
                'path' => $file->store("accreditations/{$form->id}", AccreditationDocument::disk()),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request): array
    {
        $type = $request->input('business_type');
        $rules = [
            'business_type' => ['required', Rule::in(RealtyAccreditation::BUSINESS_TYPES)],
            'firm_name' => ['required', 'string', 'max:150'],
            'residential_address' => ['required', 'string', 'max:255'],
            'tin_company' => [Rule::requiredIf($type === RealtyAccreditation::TYPE_CORPORATION), 'nullable', 'string', 'max:40'],
            'tin_personal' => ['required', 'string', 'max:40'],
            'prc_number' => ['required', 'string', 'max:60'],
            'prc_valid_until' => ['required', 'date', 'after_or_equal:today'],
            'hlurb_number' => ['nullable', 'string', 'max:60'],
            'hlurb_issued_at' => [Rule::requiredIf(filled($request->input('hlurb_number'))), 'nullable', 'date', 'before_or_equal:today'],
            'representative_name' => ['required', 'string', 'max:120'],
            'place_of_birth' => ['required', 'string', 'max:150'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:-18 years'],
            'citizenship' => ['required', 'string', 'max:80'],
            'gender' => ['required', Rule::in(RealtyAccreditation::GENDERS)],
            'civil_status' => ['required', Rule::in(RealtyAccreditation::CIVIL_STATUSES)],
            'landline' => ['nullable', 'string', 'max:40'],
            'mobile' => ['required', 'regex:/^09\d{9}$/'],
            'login_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'facebook' => ['nullable', 'string', 'max:190'],
            'years_in_real_estate' => ['required', 'integer', 'min:0', 'max:100'],
            'years_firm_operating' => ['required', 'integer', 'min:0', 'max:200'],
            'salespersons' => ['required', 'integer', 'min:0', 'max:100000'],
            'documents' => ['nullable', 'array'],
        ];

        foreach (AccreditationDocument::KINDS as $kind => $row) {
            $rules["documents.{$kind}"] = [
                Rule::requiredIf($row['required_for'] !== null && $row['required_for'] === $type),
                'nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf',
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        $messages = [
            'business_type.required' => 'Choose Corporation or Sole Proprietor.',
            'tin_company.required' => "Enter the company's tax identification number.",
            'prc_valid_until.after_or_equal' => 'The PRC registration has to be valid today or later.',
            'hlurb_issued_at.required' => 'Enter the date the HLURB registration was issued.',
            'date_of_birth.before_or_equal' => 'The representative has to be at least 18 years old.',
            'gender.required' => 'Choose Male or Female.',
            'mobile.regex' => 'Enter an 11-digit mobile number that starts with 09, e.g. 09171234567.',
            'login_email.unique' => 'There is already an account with this email.',
        ];
        foreach (AccreditationDocument::KINDS as $kind => $row) {
            $messages["documents.{$kind}.required"] = "Attach your {$row['name']}.";
            $messages["documents.{$kind}.mimes"] = 'Attach a photo (JPG, PNG, HEIC) or a PDF.';
            $messages["documents.{$kind}.max"] = 'Each file can be up to 10 MB.';
        }

        return $messages;
    }

    private function usable(string $token): RealtyAccreditation
    {
        $accreditation = RealtyAccreditation::with('developer')->where('token_hash', hash('sha256', $token))->first();
        abort_if($accreditation === null, 404, 'This link does not exist.');
        abort_if($accreditation->status !== RealtyAccreditation::STATUS_INVITED, 410, "This form was already sent in. {$accreditation->developer->name} will email you once it has been reviewed.");
        abort_if($accreditation->expires_at->isPast(), 410, "This link has expired. Ask {$accreditation->developer->name} for a new one.");

        return $accreditation;
    }

    /** A developer that takes accreditation forms on its website. */
    private function developer(string $slug): Realty
    {
        $developer = Realty::where('slug', $slug)->where('kind', Realty::KIND_DEVELOPER)->where('status', Realty::STATUS_ACTIVE)->first();
        abort_if($developer === null, 404, 'This form does not exist.');

        return $developer;
    }
}
