<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the buyer: the developer confirmed their reservation (the home is held for them), or sent it back with a reason. */
class ReservationResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offer $offer) {}

    public function envelope(): Envelope
    {
        $this->offer->loadMissing(['unit', 'project']);
        $what = $this->offer->reservation_status === 'confirmed' ? 'Reserved for you' : 'About your reservation';

        return BuyerMail::envelope($this->offer, "{$what}: {$this->offer->unit->name}, {$this->offer->project->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reservation-result', with: ['url' => Offer::url($this->offer->code)]);
    }
}
