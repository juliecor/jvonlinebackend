<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'status', 'realty_id', 'phone', 'reviewed_by', 'reviewed_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';   // jvconline staff — sees every realty

    public const ROLE_REALTY = 'realty'; // a realty's own staff — their dashboard

    public const ROLE_AGENT = 'agent';   // invited by a realty — sends buyer links

    public const STATUS_PENDING = 'pending';   // an agent who applied; can't sign in until staff approve

    public const STATUS_ACTIVE = 'active';     // can sign in

    public const STATUS_REJECTED = 'rejected'; // staff turned the application down; kept until they delete it

    /** Name prefix of a super admin's preview token: "view-as:<realty id>:<role>" (see ApplyViewAs). */
    public const VIEW_AS_TOKEN = 'view-as:';

    /** Same default as the column, so a freshly made user reads as active before it's reloaded. */
    protected $attributes = ['status' => self::STATUS_ACTIVE];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'reviewed_at' => 'datetime',
            'is_superadmin' => 'boolean',
        ];
    }

    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
    }

    /** Offers this person prepared (agents, and staff who make offers themselves). */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'agent_id');
    }

    /** The staff member who approved or rejected this agent's application. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reviewed_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** Platform admins who can also switch into any realty's dashboard as its admin or an agent. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_superadmin;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
