<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['realty_id', 'name', 'slug', 'location', 'region', 'stage', 'lat', 'lng', 'description', 'cover_path', 'hero_paths', 'site_plan_paths', 'site_map_path', 'amenities', 'official_url', 'fee_notes', 'completion_date', 'status', 'is_public'])]
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

    /** The rows of lots drawn on the site plan (the clickable map). */
    public function siteRows(): HasMany
    {
        return $this->hasMany(SiteRow::class)->orderBy('id');
    }

    /**
     * The clickable site plan: the image it is drawn on, the rows, and every lot on a row with its status.
     * Public (the website, a buyer's offer) leaves out prices. Null when the project has no site plan.
     *
     * @return array{image: string, rows: array<int, array<string, mixed>>, lots: array<int, array<string, mixed>>}|null
     */
    public function siteMap(bool $public = false): ?array
    {
        $image = self::publicUrl($this->site_map_path ?? ($this->site_plan_paths[0] ?? null));
        if (! $image) {
            return null;
        }
        $rows = $this->relationLoaded('siteRows') ? $this->siteRows : $this->siteRows()->get();
        $lots = Unit::where('project_id', $this->id)->whereNotNull('site_row_id')->orderBy('site_row_id')->orderBy('row_index')->get();

        return [
            'image' => $image,
            'rows' => $rows->map->toMap()->values()->all(),
            'lots' => $lots->map(fn (Unit $u) => [
                'id' => $u->id,
                'row_id' => $u->site_row_id,
                'index' => $u->row_index,
                'block' => $u->block,
                'lot' => $u->lot,
                'status' => $u->status,
                'model' => $u->unit_type,
                'lot_area' => $u->lot_area_sqm,
                'floor_area' => $u->area_sqm,
                'price' => $public ? null : $u->price,
                'tags' => array_values(array_intersect(array_keys(Unit::TAGS), $u->tags ?? [])),
            ])->values()->all(),
        ];
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
            // The clickable plan, when lots have been drawn on it: status only, no prices.
            'site_map' => $this->siteRows()->exists() ? $this->siteMap(public: true) : null,
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
