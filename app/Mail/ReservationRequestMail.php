<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "Rosa paid the reservation fee for Block 2 · Lot 43" — to the developer's admins (to confirm) and the agent. */
class ReservationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offer $offer, public string $dashboardUrl) {}

    public function envelope(): Envelope
    {
        $how = $this->offer->reservation_status === 'paid' ? 'paid the reservation fee online' : 'sent proof of the reservation payment';

        return new Envelope(subject: "{$this->offer->buyer_name} {$how} — {$this->offer->unit->name}, {$this->offer->project->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reservation-request');
    }
}
