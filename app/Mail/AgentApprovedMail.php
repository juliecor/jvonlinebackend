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

/** To an agent whose application was approved: their account is open and where to sign in. */
class AgentApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $agent, public Realty $realty) {}

    public function envelope(): Envelope
    {
        $reviewer = $this->agent->reviewer;

        return new Envelope(
            from: new Address(config('mail.from.address'), $this->realty->name),
            replyTo: $reviewer ? [new Address($reviewer->email, $reviewer->name)] : [],
            subject: "You're approved: welcome to {$this->realty->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.agent-approved',
            text: 'mail.agent-approved-text',
            with: ['brand' => ($this->realty->developer ?? $this->realty)->mailBrand(), 'loginUrl' => $this->realty->loginUrl()],
        );
    }
}
