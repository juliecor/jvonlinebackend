<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['realty_id', 'project_id', 'name', 'unit_type', 'category', 'floor', 'area_sqm', 'price', 'status', 'status_offer_id', 'status_by_id', 'status_at', 'floor_plan_path', 'notes', 'buyer_notes'])]
class Unit extends Model
{
    public const STATUSES = ['available', 'reserved', 'sold'];

    protected $appends = ['floor_plan_url'];

    protected function casts(): array
    {
        return ['area_sqm' => 'decimal:2', 'price' => 'decimal:2', 'status_at' => 'datetime'];
    }

    /** The offer this unit is reserved or sold through (its buyer and agent). */
    public function statusOffer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'status_offer_id');
    }

    /** Who last changed the status. */
    public function statusBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_by_id');
    }

    /**
     * Who has it, for the project's unit list and the offer: the buyer (realty
     * admins only), the agent, the offer, who marked it and when.
     *
     * @return array{offer_id: int|null, offer_code: string|null, buyer: string|null, agent: string|null, by: string|null, at: string|null}|null
     */
    public function statusDetail(bool $withBuyer): ?array
    {
        if ($this->status === 'available') {
            return null;
        }

        return [
            'offer_id' => $this->statusOffer?->id,
            'offer_code' => $this->statusOffer?->code,
            'buyer' => $withBuyer ? $this->statusOffer?->buyer_name : null,
            'agent' => $this->statusOffer?->agent?->name,
            'by' => $this->statusBy?->name,
            'at' => $this->status_at?->toIso8601String(),
        ];
    }

    /** Uploads live on the uploads disk (local or S3); a path starting with "/" or "http" (images shipped with the frontend) is used as-is. */
    public static function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, '/') || str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk(config('filesystems.uploads'))->url($path);
    }

    protected function floorPlanUrl(): Attribute
    {
        return Attribute::get(fn () => self::publicUrl($this->floor_plan_path));
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
