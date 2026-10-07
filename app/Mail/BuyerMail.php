<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/** Mail to a buyer comes in the realty's name, and replies go to their agent. */
class BuyerMail
{
    public static function envelope(Offer $offer, string $subject): Envelope
    {
        $offer->loadMissing(['realty', 'agent', 'unit', 'project']);
        $from = new Address(config('mail.from.address'), $offer->realty->name);
        $replyTo = $offer->agent?->email ? [new Address($offer->agent->email, $offer->agent->name)] : [];

        return new Envelope(from: $from, replyTo: $replyTo, subject: $subject);
    }
}
