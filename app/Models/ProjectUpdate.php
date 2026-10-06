<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month of construction photos on a project's public page. */
#[Fillable(['realty_id', 'project_id', 'month', 'label', 'photo_paths'])]
class ProjectUpdate extends Model
{
    protected $appends = ['photos', 'display_label'];

    protected function casts(): array
    {
        return ['photo_paths' => 'array'];
    }

    protected function photos(): Attribute
    {
        return Attribute::get(fn () => array_values(array_filter(array_map(fn ($p) => Project::publicUrl($p), $this->photo_paths ?? []))));
    }

    /** "June 2025" from "2025-06" unless the realty typed its own label. */
    protected function displayLabel(): Attribute
    {
        return Attribute::get(fn () => $this->label ?: Carbon::createFromFormat('Y-m', $this->month)->format('F Y'));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
