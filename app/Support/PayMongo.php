<?php

namespace App\Support;

use App\Models\Offer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paying the reservation fee online through PayMongo (GCash, Maya, cards). Only the server holds the
 * secret key; the buyer is sent to PayMongo's own checkout page and comes back when it is paid. Off
 * entirely until PAYMONGO_SECRET_KEY is set, in which case buyers upload proof of a bank payment instead.
 */
class PayMongo
{
    private const API = 'https://api.paymongo.com/v1';

    public static function enabled(): bool
    {
        return filled(config('services.paymongo.secret'));
    }

    /**
     * A checkout page for this offer's reservation fee. Returns the session id and the page to send the buyer to.
     *
     * @return array{id: string, url: string}
     */
    public static function createCheckout(Offer $offer, string $successUrl, string $cancelUrl): array
    {
        $offer->loadMissing(['unit', 'project']);
        $r = Http::withBasicAuth((string) config('services.paymongo.secret'), '')->acceptJson()->post(self::API.'/checkout_sessions', ['data' => ['attributes' => [
            'line_items' => [[
                'name' => "Reservation fee: {$offer->unit->name}, {$offer->project->name}",
                'amount' => (int) round((float) $offer->reservation_fee * 100),
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => ['gcash', 'paymaya', 'card', 'qrph'],
            'description' => "Sales offer {$offer->code}",
            'reference_number' => $offer->code,
            'send_email_receipt' => true,
            'show_description' => true,
            'show_line_items' => true,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['offer' => $offer->code],
        ]]]);
        if (! $r->successful() || ! $r->json('data.id') || ! $r->json('data.attributes.checkout_url')) {
            throw new RuntimeException('PayMongo could not open a checkout: '.($r->json('errors.0.detail') ?? $r->status()));
        }

        return ['id' => $r->json('data.id'), 'url' => $r->json('data.attributes.checkout_url')];
    }

    /**
     * What PayMongo says about a checkout: paid, and the payment's id. Used when the buyer comes back
     * before the webhook arrived.
     *
     * @return array{paid: bool, payment_id: ?string}
     */
    public static function checkout(string $id): array
    {
        $r = Http::withBasicAuth((string) config('services.paymongo.secret'), '')->acceptJson()->get(self::API.'/checkout_sessions/'.$id);
        $payments = $r->successful() ? ($r->json('data.attributes.payments') ?? []) : [];
        $paid = collect($payments)->first(fn ($p) => ($p['attributes']['status'] ?? null) === 'paid');

        return ['paid' => $paid !== null, 'payment_id' => $paid['id'] ?? null];
    }

    /** A webhook really came from PayMongo: its signature header matches the body under the webhook secret. */
    public static function verifySignature(?string $header, string $payload): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '' || ! $header) {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k] = $v;
        }
        $t = $parts['t'] ?? '';
        $expected = hash_hmac('sha256', "{$t}.{$payload}", $secret);

        return $t !== '' && (hash_equals($expected, $parts['li'] ?? '') || hash_equals($expected, $parts['te'] ?? ''));
    }
}
