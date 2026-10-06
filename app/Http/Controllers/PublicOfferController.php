<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use Illuminate\Http\JsonResponse;

/** The buyer's page: everything about one offer, by its code. Counts the view. */
class PublicOfferController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $offer = Offer::where('code', strtoupper($code))->firstOrFail();
        if ($offer->status !== 'active') {
            abort(410, 'This offer is no longer available. Please ask your agent for a new one.');
        }
        $offer->increment('views');

        return response()->json($offer->publicArray());
    }
}
