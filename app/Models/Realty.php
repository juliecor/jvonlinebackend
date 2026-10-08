<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'kind', 'developer_id', 'email', 'contact_name', 'phone', 'address', 'about', 'status', 'logo_path', 'accent_color', 'invited_at', 'registered_at'])]
class Realty extends Model
{
    public const STATUS_INVITED = 'invited'; // invite sent, form not yet filled

    public const STATUS_ACTIVE = 'active';   // registered — has a login and a page

    /** A developer owns projects and units and sells them; a broker is accredited under one and sells its units. */
    public const KIND_DEVELOPER = 'developer';

    public const KIND_BROKER = 'broker';

    /**
     * Addresses a realty can't take: a realty lives at jvconline.ph/<slug>, and these
     * already mean something there (a page, a login, a file the site serves).
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = [
        'admin', 'auth', 'home', 'johndorf', 'offer', 'platform', 'projects', 'register', 'accreditation', 'view-as',
        'api', '_next', 'login', 'logout', 'dashboard', 'join', 'password', 'www', 'jvconline', 'static', 'assets',
    ];

    /** Same default as the column, so a freshly made realty reads as a developer before it's reloaded. */
    protected $attributes = ['kind' => self::KIND_DEVELOPER];

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
     * Uploaded logos sit on the uploads disk (local or S3); a path starting with "/" or "http"
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

            return Storage::disk(config('filesystems.uploads'))->url($path);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The developer this realty is accredited under (brokers only). */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'developer_id');
    }

    /** Realties accredited under this developer. */
    public function brokers(): HasMany
    {
        return $this->hasMany(self::class, 'developer_id');
    }

    /** The address for a new realty: its name as a slug, never a reserved word, never one already taken. */
    public static function slugFor(string $name): string
    {
        // Leave room for a "-2" style suffix inside the 60 characters the login accepts.
        $base = rtrim(substr(Str::slug($name), 0, 50), '-') ?: 'realty';
        $slug = $base;
        for ($n = 2; in_array($slug, self::RESERVED_SLUGS, true) || static::where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** Where this realty's people sign in. */
    public function loginUrl(): string
    {
        return rtrim(config('app.frontend_url'), '/')."/{$this->slug}/login";
    }

    /** Where this realty's staff review its agents: applications, invites and the team. */
    public function agentsUrl(): string
    {
        return rtrim(config('app.frontend_url'), '/')."/{$this->slug}/dashboard/agents";
    }

    /** What an email needs to wear this realty's brand: absolute logo, accent colour, name and site. */
    public function mailBrand(): array
    {
        $site = rtrim(config('app.frontend_url'), '/');
        $logo = $this->logo_url;

        return [
            'name' => $this->name,
            'logo' => $logo && str_starts_with($logo, '/') ? $site.$logo : $logo,
            'accent' => preg_match('/^#[0-9a-f]{6}$/i', (string) $this->accent_color) ? $this->accent_color : '#b4241c',
            'site' => $site,
        ];
    }

    public function isDeveloper(): bool
    {
        return $this->kind === self::KIND_DEVELOPER;
    }

    public function isBroker(): bool
    {
        return $this->kind === self::KIND_BROKER;
    }

    /** Whose projects, units, plans and buyer requirements this realty works with: its developer's, or its own. */
    public function inventoryId(): int
    {
        return $this->developer_id ?? $this->id;
    }

    /** The projects this realty can see and sell. */
    public function inventoryProjects(): Builder
    {
        return Project::where('realty_id', $this->inventoryId());
    }

    /** The accreditation forms invited, sent in or decided under this developer. */
    public function accreditations(): HasMany
    {
        return $this->hasMany(RealtyAccreditation::class, 'developer_id');
    }

    /** Offers sold through this broker. */
    public function brokerOffers(): HasMany
    {
        return $this->hasMany(Offer::class, 'broker_realty_id');
    }

    /** Working agents: approved and able to sign in. */
    public function agents(): HasMany
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_AGENT)->where('status', User::STATUS_ACTIVE);
    }

    /** Agents who filled in the join form and are waiting on staff, or were turned down. */
    public function agentApplications(): HasMany
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_AGENT)->whereIn('status', [User::STATUS_PENDING, User::STATUS_REJECTED]);
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
            'kind' => $this->kind,
            // A broker's pages wear its developer's name and logo ("Accredited by Johndorf").
            'developer' => $this->developer_id ? $this->developer?->only(['name', 'slug']) + ['logo_url' => $this->developer?->logo_url] : null,
        ];
    }
}
