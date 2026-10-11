<?php

namespace App\Mail;

use App\Models\RealtyAccreditation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** An accreditation ends soon ($state "ending") or has ended ("ended"); to the realty, or to the developer's admins. */
class AccreditationPeriodMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RealtyAccreditation $form, public string $state, public bool $forDeveloper, public string $url) {}

    public function envelope(): Envelope
    {
        $who = $this->forDeveloper ? $this->form->realty->name."'s accreditation" : 'Your accreditation with '.$this->form->developer->name;

        return new Envelope(subject: $who.($this->state === 'ended' ? ' has ended' : ' ends on '.$this->form->accredited_until->format('M j, Y')));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.accreditation-period');
    }
}
