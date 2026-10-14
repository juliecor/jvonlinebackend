<?php

namespace App\Http\Controllers;

use App\Mail\ReservationRequestMail;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\User;
use App\Support\PayMongo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The buyer reserves the home from their sales offer: they pay the reservation fee the agent set,
 * online through PayMongo (when the developer set it up) or by bank deposit / GCash with a photo of
 * the proof. Either way the developer's admins confirm it (ReservationAdminController), which marks
 * the unit reserved. Nothing here touches the developer's own reservation system.
 */
class ReservationController extends Controller
{
    /** Proof of a bank or GCash payment: a photo or PDF, with the reference and a note if they like. */
    public function proof(Request $request, string $code): JsonResponse
    {
        $offer = $this->reservable($request, $code);
        $data = $request->validate([
            'proof' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['proof.mimes' => 'Upload a photo (JPG, PNG, HEIC) or a PDF of the receipt.', 'proof.max' => 'The file can be up to 10 MB.']);

        $path = $request->file('proof')->store("offers/{$offer->id}/reservation", OfferDocument::disk());
        abort_unless($path, 500, 'The file could not be saved. Please try again.');
        if ($offer->reservation_proof_path) {
            Storage::disk(OfferDocument::disk())->delete($offer->reservation_proof_path);
        }
        $offer->update([
            'reservation_status' => 'pending',
            'reservation_method' => 'proof',
            'reservation_proof_path' => $path,
            'reservation_reference' => isset($data['reference']) ? trim($data['reference']) : null,
            'reservation_note' => isset($data['note']) ? trim($data['note']) : null,
            'reservation_requested_at' => now(),
            'reservation_paid_at' => null,
            'reservation_confirmed_at' => null,
            'reservation_confirmed_by' => null,
        ]);
        $this->tellTheTeam($offer);

        return response()->json(['reservation' => $offer->fresh()->reservationArray()], 201);
    }

    /** Pay online: a PayMongo checkout page for the fee. The buyer comes back to the offer once it's paid. */
    public function checkout(Request $request, string $code): JsonResponse
    {
        $offer = $this->reservable($request, $code);
        abort_unless(PayMongo::enabled(), 422, 'Online payment is not available yet. Pay by bank deposit or GCash and upload the proof instead.');
        $url = Offer::url($offer->code);
        try {
            $session = PayMongo::createCheckout($offer, $url.'?paid=1#reserve', $url.'#reserve');
        } catch (\Throwable $e) {
            Log::warning('PayMongo checkout failed', ['offer' => $offer->code, 'error' => $e->getMessage()]);
            abort(502, 'The payment page could not be opened right now. Please try again in a moment, or pay by bank deposit or GCash.');
        }
        $offer->update([
            'reservation_status' => $offer->reservation_status === 'paid' ? 'paid' : 'pending',
            'reservation_method' => 'online',
            'reservation_checkout_id' => $session['id'],
            'reservation_requested_at' => now(),
        ]);

        return response()->json(['url' => $session['url']]);
    }

    /**
     * The buyer is back from PayMongo: ask PayMongo whether the checkout was paid, in case the webhook
     * hasn't arrived yet, and say where the reservation stands.
     */
    public function status(Request $request, string $code): JsonResponse
    {
        $offer = $this->forBuyer($request, $code);
        if ($offer->reservation_status === 'pending' && $offer->reservation_method === 'online' && $offer->reservation_checkout_id && PayMongo::enabled()) {
            try {
                $check = PayMongo::checkout($offer->reservation_checkout_id);
                if ($check['paid']) {
                    $this->markPaid($offer, $check['payment_id']);
                }
            } catch (\Throwable $e) {
                Log::warning('PayMongo status check failed', ['offer' => $offer->code, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['reservation' => $offer->fresh()->reservationArray()]);
    }

    /** PayMongo tells us a checkout was paid. Only with its signature; anything else is ignored. */
    public function webhook(Request $request): JsonResponse
    {
        abort_unless(PayMongo::verifySignature($request->header('Paymongo-Signature'), $request->getContent()), 400, 'Bad signature.');
        $event = $request->input('data.attributes.type');
        if ($event === 'checkout_session.payment.paid') {
            $checkoutId = $request->input('data.attributes.data.id');
            $paymentId = $request->input('data.attributes.data.attributes.payments.0.id') ?? $request->input('data.attributes.data.attributes.payment_intent.attributes.payments.0.id');
            $offer = $checkoutId ? Offer::where('reservation_checkout_id', $checkoutId)->first() : null;
            if ($offer && $offer->reservation_status !== 'confirmed') {
                $this->markPaid($offer, $paymentId);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** Paid online: record it once, and tell the developer's admins so they confirm the reservation. */
    private function markPaid(Offer $offer, ?string $paymentId): void
    {
        if ($offer->reservation_status === 'paid' || $offer->reservation_status === 'confirmed') {
            return;
        }
        $offer->update([
            'reservation_status' => 'paid',
            'reservation_method' => 'online',
            'reservation_reference' => $paymentId,
            'reservation_paid_at' => now(),
        ]);
        $this->tellTheTeam($offer);
    }

    /** The developer's admins (who confirm) and the agent who sent the offer. */
    private function tellTheTeam(Offer $offer): void
    {
        $offer->loadMissing(['unit', 'project', 'agent', 'realty']);
        $frontend = rtrim(config('app.frontend_url'), '/');
        $admins = User::where('realty_id', $offer->realty_id)->where('role', User::ROLE_REALTY)->where('status', User::STATUS_ACTIVE)->pluck('email')->all();
        $to = array_unique(array_filter([...$admins, $offer->agent?->email]));
        foreach ($to as $email) {
            $realty = $offer->agent && $offer->agent->email === $email ? ($offer->agent->realty ?? $offer->realty) : $offer->realty;
            try {
                Mail::to($email)->send(new ReservationRequestMail($offer, "{$frontend}/{$realty->slug}/dashboard/offers/{$offer->id}"));
            } catch (\Throwable $e) {
                Log::warning('Reservation mail failed', ['offer' => $offer->code, 'to' => $email, 'error' => $e->getMessage()]);
            }
        }
    }

    /** An offer the buyer may reserve: live, with a fee, and not already confirmed. */
    private function reservable(Request $request, string $code): Offer
    {
        $offer = $this->forBuyer($request, $code);
        abort_if($offer->reservation_fee === null, 422, 'This offer has no reservation fee yet. Ask your agent.');
        abort_if($offer->reservation_status === 'confirmed', 422, 'This home is already reserved for you.');

        return $offer;
    }

    /** A live offer the buyer signed in to (or one of the realty's own people previewing it). */
    private function forBuyer(Request $request, string $code): Offer
    {
        $offer = Offer::where('code', strtoupper($code))->firstOrFail();
        abort_unless($offer->isLive() && ! $offer->isExpired(), 410, 'This offer is no longer open.');
        $viewer = auth('sanctum')->user();
        $insider = $viewer && ($viewer->isAdmin() || $offer->isVisibleTo($viewer));
        if (! $insider && ! $offer->grantsAccess($request->header('X-Offer-Access'))) {
            abort(401, 'Sign in to this offer first, with the username and password from your agent.');
        }

        return $offer;
    }
}
