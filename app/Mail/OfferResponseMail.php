<?php

namespace App\Mail;

use App\Models\Offer;
use App\Models\OfferResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "Maria is interested in Unit 415" — to the agent who sent the offer. */
class OfferResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offer $offer, public OfferResponse $response, public string $dashboardUrl) {}

    public function envelope(): Envelope
    {
        $what = OfferResponse::LABELS[$this->response->kind] ?? 'Responded';

        return new Envelope(subject: "{$this->response->name}: {$what} — {$this->offer->unit->name}, {$this->offer->project->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.offer-response');
    }
}
