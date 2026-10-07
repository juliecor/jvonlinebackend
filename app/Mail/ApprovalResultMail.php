<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the agent: their custom terms were approved (and maybe already emailed to the buyer), or sent back with a note. */
class ApprovalResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offer $offer, public string $dashboardUrl, public ?string $emailedTo) {}

    public function envelope(): Envelope
    {
        $what = $this->offer->approval_status === 'approved' ? 'Approved' : 'Sent back';

        return new Envelope(subject: "{$what}: your offer for {$this->offer->buyer_name} — {$this->offer->unit->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.approval-result');
    }
}
