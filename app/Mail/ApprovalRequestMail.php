<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the realty's admins: an agent made an offer with custom terms that needs their OK. */
class ApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offer $offer, public string $dashboardUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "To approve: custom terms for {$this->offer->buyer_name} — {$this->offer->unit->name}, {$this->offer->project->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.approval-request');
    }
}
