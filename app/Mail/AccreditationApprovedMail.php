<?php

namespace App\Mail;

use App\Models\Realty;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To a realty whose accreditation was accepted: its username and the temporary password it must replace. */
class AccreditationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Realty $realty, public User $admin, public string $temporaryPassword, public string $loginUrl) {}

    public function envelope(): Envelope
    {
        $developer = $this->realty->developer;

        return new Envelope(
            from: new Address(config('mail.from.address'), $developer->name),
            subject: "You're accredited with {$developer->name}: your login",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.accreditation-approved',
            text: 'mail.accreditation-approved-text',
            with: ['brand' => $this->realty->developer->mailBrand()],
        );
    }
}
