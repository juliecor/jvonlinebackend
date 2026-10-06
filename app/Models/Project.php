<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['realty_id', 'name', 'location', 'description', 'cover_path', 'fee_notes', 'completion_date', 'status'])]
class Project extends Model
{
    protected $appends = ['cover_url'];

    protected function casts(): array
    {
        return ['completion_date' => 'date:Y-m-d'];
    }

    protected function coverUrl(): Attribute
    {
        return Attribute::get(fn () => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null);
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
