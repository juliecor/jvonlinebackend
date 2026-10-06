<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'slug', 'email', 'contact_name', 'phone', 'address', 'about', 'status', 'logo_path', 'accent_color', 'invited_at', 'registered_at'])]
class Realty extends Model
{
    public const STATUS_INVITED = 'invited'; // invite sent, form not yet filled

    public const STATUS_ACTIVE = 'active';   // registered — has a login and a page

    /** logo_url goes out with every realty so the frontend never has to know where logos live. */
    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /**
     * Uploaded logos sit on the public disk; a path starting with "/" or "http"
     * is used as-is (Johndorf's logo ships with the frontend).
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(function () {
            $path = $this->logo_path;
            if (! $path) {
                return null;
            }
            if (str_starts_with($path, '/') || str_starts_with($path, 'http')) {
                return $path;
            }

            return Storage::disk('public')->url($path);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_AGENT);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RealtyInvitation::class);
    }

    public function latestInvitation(): HasOne
    {
        return $this->hasOne(RealtyInvitation::class)->latestOfMany();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function agentInvitations(): HasMany
    {
        return $this->hasMany(AgentInvitation::class);
    }

    /** What anyone may see about a realty (home page, branded login). */
    public function publicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => $this->logo_url,
            'accent_color' => $this->accent_color,
            'status' => $this->status,
        ];
    }
}
