<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "3 requirements left" — what's missing or needs re-uploading, with the link straight to them. */
class RequirementsReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<int, array<string, mixed>>  $todo  requirements still missing or rejected */
    public function __construct(public Offer $offer, public array $todo, public bool $needsDetails) {}

    public function envelope(): Envelope
    {
        $n = count($this->todo) + ($this->needsDetails ? 1 : 0);

        return BuyerMail::envelope($this->offer, "{$n} requirement".($n === 1 ? '' : 's')." left for {$this->offer->unit->name}, {$this->offer->project->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.requirements-reminder', with: ['url' => Offer::url($this->offer->code).'#requirements']);
    }
}
