<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['realty_id', 'name', 'slug', 'location', 'region', 'stage', 'lat', 'lng', 'description', 'cover_path', 'hero_paths', 'site_plan_paths', 'amenities', 'official_url', 'fee_notes', 'completion_date', 'status', 'is_public'])]
class Project extends Model
{
    /** Where a project is, as buyers see it: on the dashboard, the public site and every offer. */
    public const STAGES = ['Pre-selling', 'Ongoing', 'Ready for occupancy', 'Completed', 'Sold out'];

    protected $appends = ['cover_url', 'hero_urls', 'site_plan_urls'];

    protected function casts(): array
    {
        return ['completion_date' => 'date:Y-m-d', 'lat' => 'float', 'lng' => 'float', 'is_public' => 'boolean', 'hero_paths' => 'array', 'site_plan_paths' => 'array', 'amenities' => 'array'];
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

    protected function coverUrl(): Attribute
    {
        return Attribute::get(fn () => self::publicUrl($this->cover_path));
    }

    protected function heroUrls(): Attribute
    {
        return Attribute::get(fn () => array_values(array_filter(array_map(fn ($p) => self::publicUrl($p), $this->hero_paths ?? []))));
    }

    protected function sitePlanUrls(): Attribute
    {
        return Attribute::get(fn () => array_values(array_filter(array_map(fn ($p) => self::publicUrl($p), $this->site_plan_paths ?? []))));
    }

    public function unitTypes(): HasMany
    {
        return $this->hasMany(UnitType::class)->orderBy('sort')->orderBy('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class)->orderBy('month');
    }

    /** Everything the public project page shows. */
    public function publicArray(): array
    {
        $this->loadMissing(['unitTypes', 'updates']);
        $totalPhotos = $this->updates->sum(fn ($u) => count($u->photos));

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'location' => $this->location,
            'region' => $this->region,
            'stage' => $this->stage,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'description' => $this->description,
            'cover_url' => $this->cover_url,
            'hero' => $this->hero_urls ?: array_values(array_filter([$this->cover_url])),
            'site_plans' => $this->site_plan_urls,
            'amenities' => $this->amenities ?? [],
            'official_url' => $this->official_url,
            'completion_date' => $this->completion_date?->toDateString(),
            'unit_types' => $this->unitTypes->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'specs' => $u->specs, 'images' => $u->images])->values(),
            'updates' => $this->updates->map(fn ($u) => ['id' => $u->id, 'month' => $u->month, 'label' => $u->display_label, 'photos' => $u->photos])->values(),
            'total_photos' => $totalPhotos,
        ];
    }

    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('name');
    }

    public function paymentPlans(): HasMany
    {
        return $this->hasMany(PaymentPlan::class)->orderBy('name');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
