<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every offer on the platform, optionally one realty's. Read-only for the admin. */
class OfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $offers = Offer::with(['realty:id,name,slug', 'project:id,name', 'unit:id,name,unit_type', 'agent:id,name', 'responses'])
            ->when($request->integer('realty_id'), fn ($q, $id) => $q->where('realty_id', $id))
            ->latest()
            ->take(200)
            ->get()
            ->map(fn (Offer $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'status' => $o->status,
                'buyer_name' => $o->buyer_name,
                'purchase_date' => $o->purchase_date?->toDateString(),
                'price' => (float) $o->price,
                'views' => $o->views,
                'created_at' => $o->created_at,
                'realty' => $o->realty ? ['id' => $o->realty->id, 'name' => $o->realty->name, 'slug' => $o->realty->slug] : null,
                'project' => $o->project?->name,
                'unit' => $o->unit ? trim($o->unit->name.' · '.($o->unit->unit_type ?? ''), ' ·') : null,
                'agent' => $o->agent?->name,
                'url' => Offer::url($o->code),
                'responses_count' => $o->responses->count(),
                'latest_response' => ($r = $o->responses->sortByDesc('created_at')->first()) ? ['kind' => $r->kind, 'label' => OfferResponse::LABELS[$r->kind] ?? $r->kind] : null,
            ]);

        return response()->json($offers);
    }
}
