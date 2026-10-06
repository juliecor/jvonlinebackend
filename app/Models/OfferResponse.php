<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's answer to a sales offer — the lead. */
#[Fillable(['offer_id', 'realty_id', 'kind', 'name', 'phone', 'email', 'contact_via', 'message', 'ip', 'seen_at'])]
#[Hidden(['ip'])]
class OfferResponse extends Model
{
    public const KINDS = ['interested', 'question', 'not_interested'];

    public const LABELS = ['interested' => 'Interested', 'question' => 'Has a question', 'not_interested' => 'Not interested'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** What the realty's dashboard shows for one response. */
    public function toLead(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => self::LABELS[$this->kind] ?? $this->kind,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'contact_via' => $this->contact_via,
            'message' => $this->message,
            'new' => $this->seen_at === null,
            'created_at' => $this->created_at,
        ];
    }
}
