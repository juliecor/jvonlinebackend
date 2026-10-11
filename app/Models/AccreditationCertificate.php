<?php

namespace App\Models;

use App\Support\AccreditationAgreement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A certificate of accreditation: the realty is fully accredited (accepted, signed agreement checked)
 * for a period. Kept exactly as issued; a renewal issues a new one. Anyone with its code can check
 * it on the public page, which says whether it still holds.
 */
#[Fillable([
    'realty_accreditation_id', 'number', 'code', 'firm_name', 'representative_name', 'developer_name',
    'valid_from', 'valid_until', 'signer_name', 'signer_title', 'issued_at',
])]
class AccreditationCertificate extends Model
{
    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'issued_at' => 'datetime',
        ];
    }

    public function accreditation(): BelongsTo
    {
        return $this->belongsTo(RealtyAccreditation::class, 'realty_accreditation_id');
    }

    /**
     * The certificate for a fully accredited realty's current period: issued once per period (asking again
     * gives the same one). None while the period isn't running, has no end date, or the signed copy isn't checked.
     */
    public static function issueFor(RealtyAccreditation $form): ?self
    {
        if ($form->signed_copy_status !== 'verified' || ! $form->isActive() || $form->accredited_from === null || $form->accredited_until === null) {
            return null;
        }
        $existing = $form->certificates()->whereDate('valid_from', $form->accredited_from)->whereDate('valid_until', $form->accredited_until)->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($form) {
            // One number at a time per developer: lock its row while the next number is worked out.
            $developer = Realty::whereKey($form->developer_id)->lockForUpdate()->firstOrFail();
            $prefix = self::prefix($developer->name).'-'.now('Asia/Manila')->year.'-';
            $last = self::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
            $settings = AccreditationAgreement::settings($developer);

            return $form->certificates()->create([
                'number' => $prefix.str_pad((string) ((int) Str::afterLast((string) $last, '-') + 1), 4, '0', STR_PAD_LEFT),
                'code' => self::newCode(),
                'firm_name' => $form->realty?->name ?? $form->firm_name,
                'representative_name' => $form->representative_name,
                'developer_name' => $developer->name,
                'valid_from' => $form->accredited_from->toDateString(),
                'valid_until' => $form->accredited_until->toDateString(),
                'signer_name' => $settings['representative_name'],
                'signer_title' => $settings['representative_title'],
                'issued_at' => now(),
            ]);
        });
    }

    /**
     * Whether it still holds: "valid", "expired" (its last day passed), "ended" (the developer ended the
     * accreditation) or "replaced" (a renewal issued a newer one).
     */
    public function status(): string
    {
        $form = $this->accreditation;

        return match (true) {
            $form === null || $form->status !== RealtyAccreditation::STATUS_APPROVED => 'ended',
            $form->certificates()->where('id', '>', $this->id)->exists() => 'replaced',
            $form->ended_at !== null => 'ended',
            $this->valid_until->lt(RealtyAccreditation::today()) => 'expired',
            default => 'valid',
        };
    }

    /**
     * What the certificate shows. The public check page leaves out the representative's name.
     *
     * @return array<string, mixed>
     */
    public function toPayload(bool $public = false): array
    {
        $developer = $this->accreditation?->developer;

        return [
            'number' => $this->number,
            'code' => $this->code,
            'verify_url' => rtrim((string) config('app.frontend_url'), '/').'/verify/'.$this->code,
            'firm' => $this->firm_name,
            'representative' => $public ? null : $this->representative_name,
            'developer' => ['name' => $this->developer_name, 'logo_url' => $developer?->logo_url, 'accent_color' => $developer?->accent_color],
            'from' => $this->valid_from->toDateString(),
            'until' => $this->valid_until->toDateString(),
            'issued_at' => $this->issued_at,
            'signer' => ['name' => $this->signer_name, 'title' => $this->signer_title],
            'status' => $this->status(),
        ];
    }

    /** "Johndorf Ventures Corporation" → "JVC". */
    private static function prefix(string $name): string
    {
        $initials = collect(preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY))->map(fn (string $w) => strtoupper($w[0]))->take(4)->join('');

        return $initials !== '' ? $initials : 'ACC';
    }

    /** Ten characters that are easy to read aloud and type (no 0/O, 1/I/L). */
    private static function newCode(): string
    {
        do {
            $code = collect(range(1, 10))->map(fn () => '23456789ABCDEFGHJKMNPQRSTUVWXYZ'[random_int(0, 30)])->join('');
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
