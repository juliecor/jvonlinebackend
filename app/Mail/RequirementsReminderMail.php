<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "3 requirements left": what's missing or needs re-uploading, with a link that opens the offer signed in, right on them. */
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
        $this->offer->loadMissing(['realty', 'broker', 'agent', 'unit', 'project']);

        return new Content(
            view: 'mail.requirements-reminder',
            text: 'mail.requirements-reminder-text',
            with: [
                'brand' => $this->offer->realty->mailBrand(),
                // A private offer's link signs the buyer in; the key in it runs out, so it's made when the mail is sent.
                'url' => $this->offer->requirementsUrl(),
                'days' => Offer::ENTRY_DAYS,
            ],
        );
    }
}
