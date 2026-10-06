<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['realty_id', 'invited_by', 'name', 'email', 'token_hash', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class AgentInvitation extends Model
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

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return array{0: self, 1: string} the invitation and its plain token (only the hash is stored) */
    public static function issue(Realty $realty, string $name, string $email, ?User $by): array
    {
        $token = Str::random(48);
        $invitation = static::create([
            'realty_id' => $realty->id,
            'invited_by' => $by?->id,
            'name' => $name,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::DAYS_VALID),
        ]);

        return [$invitation, $token];
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

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** The link in the email — the agent's "set your password" page on the realty's site. */
    public static function url(Realty $realty, string $token): string
    {
        return rtrim(config('app.frontend_url'), '/')."/{$realty->slug}/join/{$token}";
    }
}
