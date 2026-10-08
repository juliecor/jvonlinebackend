<?php

namespace App\Mail;

use App\Models\RealtyAccreditation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To a realty the developer wants to accredit: the link to the accreditation form. */
class AccreditationInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RealtyAccreditation $accreditation, public string $url) {}

    public function envelope(): Envelope
    {
        $developer = $this->accreditation->developer;
        $inviter = $this->accreditation->inviter;

        return new Envelope(
            from: new Address(config('mail.from.address'), $developer->name),
            replyTo: $inviter ? [new Address($inviter->email, $inviter->name)] : [],
            subject: "{$developer->name} invites you to get accredited",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.accreditation-invite',
            text: 'mail.accreditation-invite-text',
            with: ['brand' => $this->accreditation->developer->mailBrand(), 'expiresAt' => $this->accreditation->expires_at],
        );
    }
}
