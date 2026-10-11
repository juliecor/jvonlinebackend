<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Support\AccreditationAgreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The developer's details for the Broker's Accreditation Agreement: who signs for it, its office
 * address, the commission scheme and how long realties have to send the signed copy. Once these are
 * filled in, every realty agrees to the agreement on its accreditation form.
 */
class AccreditationAgreementController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $developer = $request->user()->realty;

        return response()->json([
            'settings' => AccreditationAgreement::settings($developer),
            'ready' => AccreditationAgreement::ready($developer),
            // The agreement as realties will see it, with blanks for their details.
            'preview' => AccreditationAgreement::draft($developer),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $developer = $request->user()->realty;
        $data = $request->validate([
            'representative_name' => ['required', 'string', 'max:120'],
            'representative_title' => ['nullable', 'string', 'max:120'],
            'office_address' => ['required', 'string', 'max:255'],
            'commission' => ['required', 'array', 'min:1', 'max:20'],
            'commission.*.classification' => ['required', 'string', 'max:120'],
            'commission.*.rate' => ['required', 'string', 'max:60'],
            'signed_copy_days' => ['required', 'integer', 'min:1', 'max:365'],
        ], [
            'representative_name.required' => 'Enter who signs the agreement for '.$developer->name.'.',
            'office_address.required' => 'Enter the office address that goes on the agreement.',
            'commission.required' => 'Add at least one commission rate.',
            'commission.*.classification.required' => 'Enter who the rate is for, e.g. PRC Licensed Broker.',
            'commission.*.rate.required' => 'Enter the rate, e.g. 5%.',
        ]);

        $developer->forceFill(['agreement_settings' => [
            'representative_name' => trim($data['representative_name']),
            'representative_title' => isset($data['representative_title']) ? trim($data['representative_title']) : null,
            'office_address' => trim($data['office_address']),
            'commission' => collect($data['commission'])->map(fn (array $row) => ['classification' => trim($row['classification']), 'rate' => trim($row['rate'])])->values()->all(),
            'signed_copy_days' => (int) $data['signed_copy_days'],
        ]])->save();

        return response()->json([
            'settings' => AccreditationAgreement::settings($developer),
            'ready' => AccreditationAgreement::ready($developer),
            'preview' => AccreditationAgreement::draft($developer),
        ]);
    }
}
