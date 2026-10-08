<?php

namespace App\Models;

use Database\Factories\AccreditationDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file a realty attached to its accreditation form. Stored privately; only the developer's staff can open it. */
#[Fillable(['accreditation_id', 'kind', 'path', 'original_name', 'mime', 'size'])]
#[Hidden(['path'])]
class AccreditationDocument extends Model
{
    /** @use HasFactory<AccreditationDocumentFactory> */
    use HasFactory;

    /**
     * The rows of the form's "Required documents", in order. `required_for` is the business type
     * that has to attach it; null means optional for everyone.
     *
     * @var array<string, array{name: string, label: string, required_for: string|null}>
     */
    public const KINDS = [
        'board_resolution' => [
            'name' => 'Board/Partnership Resolution',
            'label' => 'Partnership/Board Resolution authorizing the Partnership/Corporation to enter into the Accreditation and designating therein its authorized representative/s and signatory/ies to the Group Accreditation Documents (with specimen signature)',
            'required_for' => RealtyAccreditation::TYPE_CORPORATION,
        ],
        'sec_registration' => [
            'name' => 'SEC registration documents',
            'label' => "SEC Registration, Articles of Incorporation and By-Laws, Corporate Secretary's Certificate",
            'required_for' => RealtyAccreditation::TYPE_CORPORATION,
        ],
        'partnership' => [
            'name' => 'Articles of Partnership',
            'label' => 'Partnership - Articles of Partnership and By Laws',
            'required_for' => null,
        ],
        'dti_registration' => [
            'name' => 'DTI registration',
            'label' => 'Sole Proprietorship - DTI Registration Business Name',
            'required_for' => RealtyAccreditation::TYPE_SOLE_PROPRIETOR,
        ],
        'gsis' => [
            'name' => 'GSIS',
            'label' => 'GSIS',
            'required_for' => null,
        ],
    ];

    public function accreditation(): BelongsTo
    {
        return $this->belongsTo(RealtyAccreditation::class, 'accreditation_id');
    }

    public static function disk(): string
    {
        return config('filesystems.documents');
    }

    /** The rows the form shows, each with its short name and what it takes to be required. */
    public static function forForm(): array
    {
        return collect(self::KINDS)->map(fn (array $k, string $kind) => ['kind' => $kind] + $k)->values()->all();
    }
}
