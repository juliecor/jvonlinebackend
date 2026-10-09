<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One item on a realty's buyer checklist: "Valid government ID", "Proof of income"… */
#[Fillable(['realty_id', 'name', 'help', 'applies', 'sort', 'active'])]
class RequirementType extends Model
{
    /** Who has to submit it. The tied ones become required once the buyer's details say so. */
    public const APPLIES = [
        'all' => 'Every buyer',
        'optional' => 'If applicable',
        'married' => 'Married buyers',
        'co_borrower' => 'Buyers with a co-borrower',
        'income:employed' => 'Income: employed',
        'income:self_employed' => 'Income: self-employed or business',
        'income:ofw' => 'Income: OFW',
        'income:online' => 'Income: online job',
        'income:commission' => 'Income: commission-based',
        'income:rental' => 'Income: rental',
        'income:transport' => 'Income: transport franchise',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OfferDocument::class);
    }

    /**
     * For this buyer: 'required', 'optional' (if applicable, details not in yet) or 'not_needed'.
     *
     * @param  array<string, mixed>|null  $details
     */
    public function neededFor(?array $details): string
    {
        if ($this->applies === 'all') {
            return 'required';
        }
        if ($this->applies === 'optional') {
            return 'optional';
        }
        if ($details === null) {
            return 'optional';
        }
        $match = match (true) {
            $this->applies === 'married' => ($details['civil_status'] ?? null) === 'Married',
            $this->applies === 'co_borrower' => (bool) ($details['co_borrower'] ?? false),
            str_starts_with($this->applies, 'income:') => ($details['income_source'] ?? null) === substr($this->applies, 7),
            default => false,
        };

        return $match ? 'required' : 'not_needed';
    }

    /**
     * A starting checklist for a realty. Johndorf's comes from its Buyer's Guide
     * (johndorfventures.com/buyers_guide.php) and Pag-IBIG's housing loan
     * requirements; other realties start with the two every lender asks for.
     */
    /** Every realty that has no requirements yet gets the standard list. */
    public static function seedMissing(): void
    {
        Realty::whereNotIn('id', static::select('realty_id'))->get()->each(fn (Realty $realty) => static::seedDefaults($realty));
    }

    public static function seedDefaults(Realty $realty): void
    {
        if (static::where('realty_id', $realty->id)->exists()) {
            return;
        }
        $rows = [
            ['Valid government ID', "One valid ID with your photo and signature, e.g. passport, driver's license or UMID/SSS ID. Front and back.", 'all'],
            ['Proof of income', 'Your latest payslip, or a Certificate of Employment and Compensation from your employer. Self-employed: your latest Income Tax Return (ITR).', 'all'],
        ];
        if ($realty->slug === 'johndorf') {
            $rows = array_merge($rows, [
                ['Online job income', 'Notarized Certificate of Engagement and 12 months of bank statements.', 'income:online'],
                ['Commission-based income', "Notarized Certificate of Engagement and commission vouchers for the last 12 months, showing the issuer's name and contact details.", 'income:commission'],
                ['Rental income', 'Copy of the lease contract and the tax declaration.', 'income:rental'],
                ['Transport franchise income', 'Certified true copy of the transport franchise from the LGU (tricycles) or the LTFRB (other public utility vehicles).', 'income:transport'],
                ["Co-borrower's ID and proof of income", 'Johndorf only allows immediate family as co-borrower: parents, siblings or children.', 'co_borrower'],
            ]);
        }
        foreach ($rows as $i => [$name, $help, $applies]) {
            static::create(['realty_id' => $realty->id, 'name' => $name, 'help' => $help, 'applies' => $applies, 'sort' => ($i + 1) * 10]);
        }
    }
}
