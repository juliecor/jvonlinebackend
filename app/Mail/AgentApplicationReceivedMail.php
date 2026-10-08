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

/** To a realty's admins: an invited agent sent in their application and waits for a decision. */
class AgentApplicationReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $applicant, public Realty $realty) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->realty->name),
            // Replying goes straight to the person who applied.
            replyTo: [new Address($this->applicant->email, $this->applicant->name)],
            subject: "{$this->applicant->name} applied to join {$this->realty->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.agent-application-received',
            text: 'mail.agent-application-received-text',
            with: ['brand' => ($this->realty->developer ?? $this->realty)->mailBrand(), 'reviewUrl' => $this->realty->agentsUrl()],
        );
    }
}
