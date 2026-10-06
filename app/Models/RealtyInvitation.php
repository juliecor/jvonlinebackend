<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['realty_id', 'email', 'token_hash', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class RealtyInvitation extends Model
{
    public const DAYS_VALID = 7;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
    }

    /**
     * Create an invitation and return it with the plain token (only known here
     * and in the email — the table keeps the hash).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Realty $realty, string $email): array
    {
        $token = Str::random(48);
        $invitation = static::create([
            'realty_id' => $realty->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::DAYS_VALID),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** The link in the email — the registration form on the Next.js site. */
    public static function url(string $token): string
    {
        return rtrim(config('app.frontend_url'), '/').'/register/'.$token;
    }
}
