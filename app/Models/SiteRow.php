<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of lots drawn on a project's site plan: four corners (fractions of the image, clockwise from
 * the outer corner of the first lot), the block, the first lot's number and how many lots. The lots
 * are cut along corner 1 → corner 2; each is a unit with its row_index.
 */
#[Fillable(['realty_id', 'project_id', 'block', 'corners', 'first_lot', 'lot_count'])]
class SiteRow extends Model
{
    public const MAX_LOTS = 200;

    protected function casts(): array
    {
        return ['corners' => 'array', 'first_lot' => 'integer', 'lot_count' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('row_index');
    }

    /** Lot numbers this row covers. @return array<int, int> */
    public function lotNumbers(): array
    {
        return range($this->first_lot, $this->first_lot + $this->lot_count - 1);
    }

    /** @return array<string, mixed> */
    public function toMap(): array
    {
        return ['id' => $this->id, 'block' => $this->block, 'corners' => $this->corners, 'first_lot' => $this->first_lot, 'lot_count' => $this->lot_count];
    }

    /** "Block 4 · Lot 23", the way lots are named elsewhere. */
    public static function lotName(string $block, int|string $lot): string
    {
        return "Block {$block} · Lot {$lot}";
    }
}
