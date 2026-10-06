<?php

namespace App\Mail;

use App\Models\Realty;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "You're invited to jvconline" — carries the registration link. */
class RealtyInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Realty $realty,
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->realty->name} — your invitation to jvconline");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.realty-invitation');
    }
}
