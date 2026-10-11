<?php

namespace App\Http\Controllers;

use App\Models\AccreditationCertificate;
use Illuminate\Http\JsonResponse;

/**
 * jvconline.ph/verify/<code>: anyone (a buyer, a bank) checks that a certificate of accreditation is real
 * and still holds. Only what the certificate itself shows, without the representative's name.
 */
class PublicCertificateController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $certificate = AccreditationCertificate::with('accreditation.developer')->where('code', strtoupper(trim($code)))->first();
        abort_if($certificate === null, 404, 'There is no certificate with this code. Check the code on the certificate and try again.');

        return response()->json($certificate->toPayload(public: true));
    }
}
