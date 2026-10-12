<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A row of lots drawn on a project's site plan: its outline (fractions of the image, clockwise from the
 * outer corner of the first lot: the road side, then the back side with as many points; four for a
 * straight row, more where it bends), the block, the first lot's number and how many lots. The lots are
 * cut along the road side; each is a unit with its row_index.
 */
#[Fillable(['realty_id', 'project_id', 'block', 'corners', 'first_lot', 'lot_count'])]
class SiteRow extends Model
{
    /** The most points a row's outline can have: 20 across the row, at its ends and bends. */
    public const MAX_CORNERS = 40;

    public const MAX_LOTS = 200;

    protected function casts(): array
    {
        return ['corners' => 'array', 'first_lot' => 'integer', 'lot_count' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('row_index');
    }

    /** Lot numbers this row covers. @return array<int, int> */
    public function lotNumbers(): array
    {
        return range($this->first_lot, $this->first_lot + $this->lot_count - 1);
    }

    /** @return array<string, mixed> */
    public function toMap(): array
    {
        return ['id' => $this->id, 'block' => $this->block, 'corners' => $this->corners, 'first_lot' => $this->first_lot, 'lot_count' => $this->lot_count];
    }

    /** "Block 4 · Lot 23", the way lots are named elsewhere. */
    public static function lotName(string $block, int|string $lot): string
    {
        return "Block {$block} · Lot {$lot}";
    }

    /** @return array<string, array<int, mixed>> */
    public static function cornerRules(): array
    {
        return [
            'corners' => ['required', 'array', 'min:4', 'max:'.self::MAX_CORNERS, function (string $attribute, mixed $value, Closure $fail) {
                if (is_array($value) && count($value) % 2) {
                    $fail('Every point on the road side needs one straight across from it at the back.');
                }
            }],
            'corners.*' => ['required', 'array', 'size:2'],
            'corners.*.*' => ['required', 'numeric', 'between:-0.05,1.05'],
        ];
    }

    /** What lots can be given together: a model, areas, price, category. @return array<string, array<int, mixed>> */
    public static function lotRules(): array
    {
        return [
            'unit_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lot_area_sqm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'area_sqm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    /** A new row: its outline, block, first lot, how many, and what its lots share. @return array<string, array<int, mixed>> */
    public static function newRowRules(): array
    {
        return self::cornerRules() + [
            'block' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/'],
            'first_lot' => ['required', 'integer', 'min:1', 'max:99999'],
            'lot_count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_LOTS],
        ] + self::lotRules();
    }

    /**
     * Draw a row on the project's map (data checked with newRowRules): the row, and a unit for every lot.
     * A unit the project already has for that block and lot (or by its name) is linked instead: it keeps
     * what it says and only fills in what it is missing. `$lots` gives single lots their own values, by
     * their place in the row. Lots already on the map can't be drawn again.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lots
     */
    public static function draw(Project $project, array $data, array $lots = []): self
    {
        $block = trim($data['block']);
        $numbers = range($data['first_lot'], $data['first_lot'] + $data['lot_count'] - 1);

        $taken = Unit::where('project_id', $project->id)->whereNotNull('site_row_id')->where('block', $block)->whereIn('lot', array_map('strval', $numbers))->pluck('lot')->sortBy(fn ($l) => (int) $l)->values();
        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages(['first_lot' => "Block {$block} lot".($taken->count() === 1 ? ' ' : 's ').$taken->join(', ').($taken->count() === 1 ? ' is' : ' are').' already on the map.']);
        }

        return DB::transaction(function () use ($project, $data, $lots, $block, $numbers) {
            $row = self::create(['realty_id' => $project->realty_id, 'project_id' => $project->id, 'block' => $block, 'corners' => $data['corners'], 'first_lot' => $data['first_lot'], 'lot_count' => $data['lot_count']]);
            $shared = ['unit_type' => $data['unit_type'] ?? null, 'lot_area_sqm' => $data['lot_area_sqm'] ?? null, 'area_sqm' => $data['area_sqm'] ?? null, 'price' => $data['price'] ?? null];
            foreach ($numbers as $i => $n) {
                $values = array_merge($shared, array_intersect_key($lots[$i] ?? [], array_flip(['unit_type', 'lot_area_sqm', 'area_sqm', 'price', 'tags'])));
                $name = self::lotName($block, $n);
                $unit = Unit::where('project_id', $project->id)->whereNull('site_row_id')
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('block', $block)->where('lot', (string) $n))->orWhere('name', $name))
                    ->first();
                $place = ['block' => $block, 'lot' => (string) $n, 'site_row_id' => $row->id, 'row_index' => $i];
                if ($unit) {
                    $unit->update($place + array_filter($values, fn ($v, $k) => $unit->{$k} === null && $v !== null, ARRAY_FILTER_USE_BOTH));
                } else {
                    Unit::create($place + $values + ['realty_id' => $project->realty_id, 'project_id' => $project->id, 'name' => $name, 'category' => $data['category'] ?? 'Residential', 'status' => 'available']);
                }
            }

            return $row;
        });
    }
}
