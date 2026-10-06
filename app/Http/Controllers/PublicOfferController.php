<?php

namespace App\Http\Controllers;

use App\Mail\OfferResponseMail;
use App\Models\Offer;
use App\Models\OfferResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/** The buyer's page: one offer by its code (counts the view), and the buyer's answer to it. */
class PublicOfferController extends Controller
{
    public function show(Request $request, string $code): JsonResponse
    {
        $offer = $this->active($code);

        // The realty's own people (and admins) opening the link don't count as a buyer view.
        $viewer = auth('sanctum')->user();
        $insider = $viewer instanceof User && ($viewer->isAdmin() || $viewer->realty_id === $offer->realty_id);
        if (! $insider) {
            $offer->forceFill([
                'views' => $offer->views + 1,
                'first_viewed_at' => $offer->first_viewed_at ?? now(),
                'last_viewed_at' => now(),
            ])->save();
        }

        return response()->json($offer->publicArray());
    }

    public function respond(Request $request, string $code): JsonResponse
    {
        $offer = $this->active($code);
        $data = $request->validate([
            'kind' => ['required', Rule::in(OfferResponse::KINDS)],
            'name' => ['required', 'string', 'max:120'],
            'phone' => [Rule::requiredIf($request->input('kind') !== 'not_interested'), 'nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'contact_via' => ['nullable', Rule::in(['call', 'viber', 'whatsapp', 'sms', 'email'])],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'max:0'], // honeypot: people never see it, bots fill it
        ]);

        $response = OfferResponse::create([
            'offer_id' => $offer->id,
            'realty_id' => $offer->realty_id,
            'kind' => $data['kind'],
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'contact_via' => $data['contact_via'] ?? null,
            'message' => $data['message'] ?? null,
            'ip' => $request->ip(),
        ]);

        $to = $offer->agent?->email ?? $offer->realty->email;
        if ($to) {
            try {
                $url = rtrim(config('app.frontend_url'), '/')."/{$offer->realty->slug}/dashboard/offers/{$offer->id}";
                Mail::to($to)->send(new OfferResponseMail($offer->load(['unit', 'project']), $response, $url));
            } catch (\Throwable $e) {
                // The lead is saved either way; a mail hiccup shouldn't lose it or show the buyer an error.
                Log::warning('Offer response mail failed', ['offer' => $offer->code, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true, 'kind' => $response->kind], 201);
    }

    private function active(string $code): Offer
    {
        $offer = Offer::with(['realty', 'agent'])->where('code', strtoupper($code))->firstOrFail();
        if ($offer->status !== 'active') {
            abort(410, 'This offer is no longer available. Please ask your agent for a new one.');
        }

        return $offer;
    }
}
