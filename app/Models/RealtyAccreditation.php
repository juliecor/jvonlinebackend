<?php

namespace App\Models;

use Database\Factories\RealtyAccreditationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A realty's accreditation under a developer (Johndorf): the invite (a link that is the
 * credential), the form the realty fills in, and the developer's decision. Accepting it
 * creates the broker realty and its first admin.
 */
#[Fillable([
    'developer_id', 'invited_by', 'email', 'token_hash', 'expires_at', 'status', 'submitted_at', 'submitted_ip',
    'business_type', 'firm_name', 'residential_address', 'tin_company', 'tin_personal', 'prc_number', 'prc_valid_until', 'hlurb_number', 'hlurb_issued_at',
    'representative_name', 'place_of_birth', 'date_of_birth', 'citizenship', 'gender', 'civil_status', 'landline', 'mobile', 'login_email', 'facebook',
    'years_in_real_estate', 'years_firm_operating', 'salespersons',
    'reviewed_by', 'reviewed_at', 'review_note', 'realty_id',
    'agreement_version', 'agreement', 'agreed_name', 'agreed_at', 'agreed_ip',
    'accredited_from', 'accredited_until', 'ended_at', 'end_note',
    'signed_copy_due', 'signed_copy_status', 'signed_copy_note', 'signed_copy_checked_by', 'signed_copy_checked_at',
    'expiry_reminded_at', 'ended_notified_at',
])]
#[Hidden(['token_hash', 'submitted_ip'])]
class RealtyAccreditation extends Model
{
    /** @use HasFactory<RealtyAccreditationFactory> */
    use HasFactory;

    public const DAYS_VALID = 7;

    public const STATUS_INVITED = 'invited';     // the link is out, the form isn't filled in

    public const STATUS_SUBMITTED = 'submitted'; // waiting for the developer's staff

    public const STATUS_APPROVED = 'approved';   // accepted: the broker realty exists

    public const STATUS_REJECTED = 'rejected';

    public const TYPE_CORPORATION = 'corporation';

    public const TYPE_SOLE_PROPRIETOR = 'sole_proprietor';

    public const BUSINESS_TYPES = [self::TYPE_CORPORATION, self::TYPE_SOLE_PROPRIETOR];

    public const GENDERS = ['male', 'female'];

    public const CIVIL_STATUSES = ['Single', 'Married', 'Widowed', 'Separated'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'prc_valid_until' => 'date:Y-m-d',
            'hlurb_issued_at' => 'date:Y-m-d',
            'date_of_birth' => 'date:Y-m-d',
            // Government ID numbers: recoverable for the reviewer, never readable straight from the database.
            'tin_company' => 'encrypted',
            'tin_personal' => 'encrypted',
            'agreement' => 'array',
            'agreed_at' => 'datetime',
            'accredited_from' => 'date:Y-m-d',
            'accredited_until' => 'date:Y-m-d',
            'ended_at' => 'datetime',
            'signed_copy_due' => 'date:Y-m-d',
            'signed_copy_checked_at' => 'datetime',
            'expiry_reminded_at' => 'datetime',
            'ended_notified_at' => 'datetime',
        ];
    }

    /** Days before the end when the reminders go out and the period shows as ending soon. */
    public const ENDING_SOON_DAYS = 30;

    /** An accepted accreditation that hasn't ended early or run past its last day. */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->ended_at === null
            && ($this->accredited_until === null || ! $this->accredited_until->lt(self::today()));
    }

    /**
     * Where an accepted accreditation stands: "active", "ending" (within 30 days), "expired" (ran out),
     * "ended" (the developer ended it early) or "open" (accepted before periods existed: no end set).
     */
    public function periodState(): string
    {
        return match (true) {
            $this->ended_at !== null => 'ended',
            $this->accredited_until === null => 'open',
            $this->accredited_until->lt(self::today()) => 'expired',
            $this->daysLeft() <= self::ENDING_SOON_DAYS => 'ending',
            default => 'active',
        };
    }

    /** Days until the last day (0 on it), or null without an end date. */
    public function daysLeft(): ?int
    {
        return $this->accredited_until ? (int) self::today()->diffInDays($this->accredited_until, false) : null;
    }

    /** Today's date in the Philippines, at midnight like the stored dates (so a date compares to a date). */
    public static function today(): Carbon
    {
        return Carbon::parse(now('Asia/Manila')->toDateString());
    }

    public function developer(): BelongsTo
    {
        return $this->belongsTo(Realty::class, 'developer_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The broker realty made when this was accepted. */
    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AccreditationDocument::class, 'accreditation_id');
    }

    /** @return array{0: self, 1: string} the invitation and its plain token (only the hash is stored) */
    public static function issue(Realty $developer, string $email, ?User $by): array
    {
        $token = Str::random(48);
        $accreditation = static::create([
            'developer_id' => $developer->id,
            'invited_by' => $by?->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::DAYS_VALID),
            'status' => self::STATUS_INVITED,
        ]);

        return [$accreditation, $token];
    }

    /** A new link for the same invitation (the old one stops working). Returns the plain token. */
    public function refresh(): string
    {
        $token = Str::random(48);
        $this->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::DAYS_VALID)]);

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }

    /** The form can still be filled in: sent, not yet submitted, not expired. */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_INVITED && $this->expires_at->isFuture();
    }

    /** The link in the email: the accreditation form on the public site. */
    public static function url(string $token): string
    {
        return rtrim(config('app.frontend_url'), '/')."/accreditation/{$token}";
    }
}
