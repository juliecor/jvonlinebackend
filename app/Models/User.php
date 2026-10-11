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

#[Fillable(['name', 'email', 'password', 'role', 'permissions', 'status', 'realty_id', 'phone', 'reviewed_by', 'reviewed_at'])]
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

    public const STATUS_DISABLED = 'disabled'; // a staff account its admin turned off: can't sign in, history kept

    /**
     * The dashboard sections a realty's admin can give a staff member, with
     * what each one means. A staff member whose permissions are null has them
     * all and can manage the other staff accounts too (a full admin).
     */
    public const SECTIONS = [
        'offers' => 'Offers: every offer, making offers, buyers\' documents, marking units reserved or sold',
        'approvals' => 'Approvals: approve or send back custom terms',
        'projects' => 'Projects: add and edit projects, units, payment plans and website pages',
        'agents' => 'Agents: invite, approve and remove agents',
        'realties' => 'Realty Company Invitation: invite and accredit realties',
        'requirements' => 'Buyer requirements: what buyers have to send',
        'ai' => 'Johndorf AI',
    ];

    /** Sections only a developer (Johndorf) has; an accredited realty sells, it doesn't edit the inventory. */
    public const DEVELOPER_SECTIONS = ['approvals', 'projects', 'realties', 'requirements', 'ai'];

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
            'must_change_password' => 'boolean',
            'permissions' => 'array',
            'last_login_at' => 'datetime',
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

    /** A realty admin with every section, who can also manage the other staff accounts. */
    public function isFullAdmin(): bool
    {
        return $this->role === self::ROLE_REALTY && $this->permissions === null;
    }

    /**
     * May this person use a dashboard section? Staff only have the sections
     * their admin gave them; "staff" (managing staff accounts) is for full
     * admins. Agents aren't limited by sections: their own checks apply.
     */
    public function canUse(string $section): bool
    {
        if ($section === 'staff') {
            return $this->isFullAdmin();
        }
        if ($this->role !== self::ROLE_REALTY) {
            return true;
        }

        return $this->permissions === null || in_array($section, $this->permissions, true);
    }

    /**
     * The sections a realty offers its staff: all of them for the developer,
     * the selling ones for an accredited realty.
     *
     * @return array<int, string>
     */
    public static function sectionsFor(?Realty $realty): array
    {
        $all = array_keys(self::SECTIONS);

        return $realty?->isDeveloper() ? $all : array_values(array_diff($all, self::DEVELOPER_SECTIONS));
    }

    /**
     * What the dashboard shows this person: the sections they can use, plus
     * "staff" for a full admin. Agents get none (their pages follow their role).
     *
     * @return array<int, string>
     */
    public function access(): array
    {
        if ($this->role !== self::ROLE_REALTY) {
            return [];
        }
        $sections = array_values(array_filter(self::sectionsFor($this->realty), fn (string $s) => $this->canUse($s)));

        return $this->isFullAdmin() ? [...$sections, 'staff'] : $sections;
    }

    /** Staff or an agent of a developer realty (Johndorf): they work with the inventory itself. */
    public function isDeveloperMember(): bool
    {
        return in_array($this->role, [self::ROLE_REALTY, self::ROLE_AGENT], true) && (bool) $this->realty?->isDeveloper();
    }

    /** A developer realty's admin: edits projects and units, approves custom terms, sets unit status. */
    public function isDeveloperStaff(): bool
    {
        return $this->role === self::ROLE_REALTY && (bool) $this->realty?->isDeveloper();
    }

    /** Staff or an agent of an accredited realty: they sell the developer's units but don't edit them. */
    public function isBrokerMember(): bool
    {
        return in_array($this->role, [self::ROLE_REALTY, self::ROLE_AGENT], true) && (bool) $this->realty?->isBroker();
    }
}
