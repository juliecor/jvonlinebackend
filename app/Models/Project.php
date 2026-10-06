<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['realty_id', 'name', 'location', 'lat', 'lng', 'description', 'cover_path', 'fee_notes', 'completion_date', 'status'])]
class Project extends Model
{
    protected $appends = ['cover_url'];

    protected function casts(): array
    {
        return ['completion_date' => 'date:Y-m-d', 'lat' => 'float', 'lng' => 'float'];
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
