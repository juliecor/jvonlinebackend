<?php

namespace App\Mail;

use App\Models\Offer;
use App\Models\RequirementType;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The sales offer, emailed to the buyer: the link, and that requirements go on the same page. */
class OfferToBuyerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offer $offer) {}

    public function envelope(): Envelope
    {
        return BuyerMail::envelope($this->offer, "Your sales offer: {$this->offer->unit->name}, {$this->offer->project->name}");
    }

    public function content(): Content
    {
        $requirements = RequirementType::where('realty_id', $this->offer->realty_id)->where('active', true)->exists();

        return new Content(markdown: 'mail.offer-to-buyer', with: ['url' => Offer::url($this->offer->code), 'requirements' => $requirements]);
    }
}
