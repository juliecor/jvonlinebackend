<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file the buyer uploaded for one requirement. Stored privately; only the realty can open it. */
#[Fillable(['offer_id', 'realty_id', 'requirement_type_id', 'path', 'original_name', 'mime', 'size', 'status', 'note', 'reviewed_by', 'reviewed_at', 'ip'])]
#[Hidden(['path', 'ip'])]
class OfferDocument extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(RequirementType::class, 'requirement_type_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function disk(): string
    {
        return config('filesystems.documents');
    }
}
