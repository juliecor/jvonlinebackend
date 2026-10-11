<?php

namespace App\Mail;

use App\Models\AccreditationCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Congratulations: the realty is fully accredited (or renewed) and its certificate is ready in the dashboard. */
class AccreditationCertificateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AccreditationCertificate $certificate, public bool $renewed, public string $url) {}

    public function envelope(): Envelope
    {
        $c = $this->certificate;

        return new Envelope(subject: $this->renewed
            ? "Your accreditation with {$c->developer_name} is renewed: your new certificate"
            : "Congratulations! {$c->firm_name} is fully accredited by {$c->developer_name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.accreditation-certificate');
    }
}
