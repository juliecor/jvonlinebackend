<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Mail\ReservationResultMail;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Support\PayMongo;
use App\Support\QuietMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The developer's admins settle a buyer's reservation: confirm it (the unit turns reserved for this
 * buyer, the buyer is told), or send it back with a reason. They also type, once, where buyers pay
 * the fee by bank or GCash.
 */
class ReservationAdminController extends Controller
{
    /** Confirm: the fee is in, the unit is reserved for this buyer. */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isDeveloperStaff(), 403, "Only the developer's admins confirm reservations.");
        $offer = Offer::visibleTo($user)->with('unit.statusOffer:id,realty_id,broker_realty_id,code,buyer_name,agent_id')->findOrFail($id);
        abort_if($offer->reservation_fee === null, 422, 'This offer has no reservation fee.');
        abort_unless($offer->status === 'active', 422, 'This offer is void.');
        $unit = $offer->unit;
        abort_unless($unit !== null, 404, 'This offer has no unit.');
        $other = $unit->status !== 'available' && $unit->status_offer_id && $unit->status_offer_id !== $offer->id ? $unit->statusOffer : null;
        if ($other) {
            return response()->json(['message' => "This unit is already {$unit->status} to {$other->buyer_name} (offer {$other->code}). Set it back to available on that offer first."], 409);
        }

        $offer->update(['reservation_status' => 'confirmed', 'reservation_confirmed_at' => now(), 'reservation_confirmed_by' => $user->id, 'reservation_paid_at' => $offer->reservation_paid_at ?? now()]);
        if ($unit->status !== 'sold') {
            $unit->update(['status' => 'reserved', 'status_offer_id' => $offer->id, 'status_by_id' => $user->id, 'status_at' => now()]);
        }
        $emailed = $this->tellTheBuyer($offer);

        return response()->json(['reservation' => $this->adminArray($offer->fresh()), 'emailed' => $emailed]);
    }

    /** Send it back: the proof didn't check out. The buyer sees the reason and can send a new one. */
    public function decline(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isDeveloperStaff(), 403, "Only the developer's admins settle reservations.");
        $offer = Offer::visibleTo($user)->findOrFail($id);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Tell the buyer why, so they can fix it.']);
        abort_unless($offer->reservationWaiting(), 422, 'There is no reservation waiting on this offer.');

        $offer->update(['reservation_status' => 'declined', 'reservation_note' => trim($data['note'])]);
        $emailed = $this->tellTheBuyer($offer);

        return response()->json(['reservation' => $this->adminArray($offer->fresh()), 'emailed' => $emailed]);
    }

    /** The photo or PDF the buyer uploaded as proof, for the realty's own people. */
    public function proof(Request $request, int $id)
    {
        $offer = Offer::visibleTo($request->user())->findOrFail($id);
        abort_unless($offer->reservation_proof_path, 404, 'No proof was uploaded.');
        $disk = Storage::disk(OfferDocument::disk());
        abort_unless($disk->exists($offer->reservation_proof_path), 404, 'The file is missing from storage.');

        return $disk->response($offer->reservation_proof_path, 'reservation-proof-'.$offer->code.'.'.pathinfo($offer->reservation_proof_path, PATHINFO_EXTENSION), [
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /** Where buyers pay the fee (bank account, GCash number), shown on every offer with a fee. */
    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isDeveloperStaff(), 403, "Only the developer's admins change this.");
        if ($request->isMethod('post')) {
            $data = $request->validate(['payment_instructions' => ['nullable', 'string', 'max:2000']]);
            $user->realty->update(['payment_instructions' => isset($data['payment_instructions']) ? trim($data['payment_instructions']) ?: null : null]);
        }

        return response()->json(['payment_instructions' => $user->realty->fresh()->payment_instructions, 'online' => PayMongo::enabled()]);
    }

    /**
     * The reservation as the dashboard shows it.
     *
     * @return array<string, mixed>|null
     */
    public static function adminArray(Offer $offer): ?array
    {
        $a = $offer->reservationArray();
        if ($a === null) {
            return null;
        }
        unset($a['instructions']);
        $offer->loadMissing('reservationConfirmer:id,name');

        return $a + ['confirmed_by' => $offer->reservationConfirmer?->name];
    }

    private function tellTheBuyer(Offer $offer): ?string
    {
        $to = $offer->buyerEmail();
        if (! $to) {
            return null;
        }

        return QuietMail::send($to, new ReservationResultMail($offer->fresh()), "reservation {$offer->reservation_status}") ? $to : null;
    }
}
