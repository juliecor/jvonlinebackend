<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDocument;
use App\Models\RealtyAccreditation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The accreditation form a realty reaches from its invite email (jvconline.ph/accreditation/<token>).
 * The token is the credential, so nothing here needs a login; the developer's staff review what is sent.
 */
class AccreditationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $accreditation = $this->usable($token);

        return response()->json([
            'developer' => $accreditation->developer->publicArray(),
            'email' => $accreditation->email,
            'expires_at' => $accreditation->expires_at,
            'documents' => AccreditationDocument::forForm(),
            'civil_statuses' => RealtyAccreditation::CIVIL_STATUSES,
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $accreditation = $this->usable($token);
        $data = $request->validate($this->rules($request), $this->messages());
        $fields = collect($data)->except('documents')->all();

        DB::transaction(function () use ($request, $accreditation, $fields) {
            // Two clicks on Submit must not file it twice.
            $form = RealtyAccreditation::whereKey($accreditation->id)->lockForUpdate()->firstOrFail();
            abort_unless($form->isUsable(), 410, 'This form was already submitted.');

            $form->update($fields + [
                'status' => RealtyAccreditation::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_ip' => $request->ip(),
            ]);

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
        });

        return response()->json(['ok' => true], 201);
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
}
