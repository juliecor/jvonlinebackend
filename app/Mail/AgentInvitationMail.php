<?php

namespace App\Mail;

use App\Models\Realty;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "<Realty> invited you" — carries the agent's join link. */
class AgentInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Realty $realty,
        public string $agentName,
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->realty->name} invited you to join on jvonline");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.agent-invitation');
    }
}
