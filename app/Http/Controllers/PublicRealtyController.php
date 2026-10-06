<?php

namespace App\Http\Controllers;

use App\Models\Realty;
use Illuminate\Http\JsonResponse;

/** What anyone may see: the realties that are live (home page) and one realty's name + logo (its login page). */
class PublicRealtyController extends Controller
{
    public function index(): JsonResponse
    {
        $realties = Realty::where('status', Realty::STATUS_ACTIVE)
            ->orderBy('registered_at')
            ->get()
            ->map(fn (Realty $r) => $r->publicArray() + ['registered_at' => $r->registered_at]);

        return response()->json($realties);
    }

    public function show(string $slug): JsonResponse
    {
        $realty = Realty::where('slug', $slug)->where('status', Realty::STATUS_ACTIVE)->firstOrFail();

        return response()->json($realty->publicArray());
    }
}
