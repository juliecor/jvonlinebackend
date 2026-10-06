<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A house or unit model on a project's public page: its renders and spec sheet. */
#[Fillable(['realty_id', 'project_id', 'name', 'specs', 'image_paths', 'sort'])]
class UnitType extends Model
{
    public const SPEC_KEYS = ['usable_floor_area', 'typical_floor_area', 'bedrooms', 'baths', 'floors', 'parking'];

    protected $appends = ['images'];

    protected function casts(): array
    {
        return ['specs' => 'array', 'image_paths' => 'array'];
    }

    protected function images(): Attribute
    {
        return Attribute::get(fn () => array_values(array_filter(array_map(fn ($p) => Project::publicUrl($p), $this->image_paths ?? []))));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
