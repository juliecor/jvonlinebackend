<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['realty_id', 'project_id', 'name', 'unit_type', 'category', 'floor', 'area_sqm', 'price', 'status', 'floor_plan_path', 'notes'])]
class Unit extends Model
{
    public const STATUSES = ['available', 'reserved', 'sold'];

    protected $appends = ['floor_plan_url'];

    protected function casts(): array
    {
        return ['area_sqm' => 'decimal:2', 'price' => 'decimal:2'];
    }

    protected function floorPlanUrl(): Attribute
    {
        return Attribute::get(fn () => $this->floor_plan_path ? Storage::disk('public')->url($this->floor_plan_path) : null);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
