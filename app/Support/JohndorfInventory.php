<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Parses Johndorf's legacy per-unit reservation inventory page
 * (https://johndorfventures.com/reservation/project_unit_list.php?RealtyID=<n>)
 * into plain arrays for the johndorf:scrape-units command to write to JSON.
 *
 * Pure — no I/O, no database, no Laravel facades beyond Number::ordinal() —
 * so it is tested directly against saved fixture pages (tests/Fixtures/johndorf).
 *
 * The page's own HTML is rough, and every quirk below is quoted exactly as
 * Johndorf's site renders it, not a bug to "clean up": a stray </a> in the
 * unit-code cell, a "Discount:" row that closes with </div> instead of
 * </td>, inconsistent whitespace around the "1st-Nth" monthly-amortization
 * row, and an unescaped "&MF" in "Equity&MF @ N mo.".
 */
class JohndorfInventory
{
    /**
     * This project's raw "Unit Type" column values, mapped to the UnitType
     * name johndorf-public.json already seeded for it (Offer::publicArray()
     * matches a unit's unit_type against UnitType names exactly). A raw
     * value with no entry here is never guessed — parse() reports it as a
     * row error and drops that one row instead of inventing a mapping.
     *
     * @var array<string, array<string, string>>
     */
    public const UNIT_TYPES = [
        'plumera' => [
            'studio' => 'Studio Unit',
            '1br' => '1BR Unit',
        ],
        'tierranava-carcar' => [
            'inner' => 'Two-Storey Townhouse',
            'end' => 'Two-Storey Townhouse',
            'corner' => 'Two-Storey Townhouse',
        ],
    ];

    /**
     * @return array{
     *     inventory_state: "listed"|"no_available_units"|"error",
     *     available_count: int,
     *     requirements_days: int|null,
     *     units: array<int, array<string, mixed>>,
     *     errors: array<int, string>,
     * }
     */
    public static function parse(string $html, string $slug, string $expectedTitle): array
    {
        $html = str_replace("\r\n", "\n", $html);

        if (! preg_match('/<title>\s*(.*?)\s*Project Inventory\s*<\/title>/s', $html, $m) || trim($m[1]) !== $expectedTitle) {
            return self::fatal("the page title wasn't \"{$expectedTitle} Project Inventory\" — Johndorf may have renumbered this project");
        }

        $tableAt = strpos($html, 'id="mainTable"');
        if ($tableAt === false) {
            return self::fatal('no #mainTable on the page');
        }

        $bodyStart = strpos($html, '<tbody>', $tableAt);
        $bodyEnd = $bodyStart === false ? false : strpos($html, '</tbody>', $bodyStart);
        if ($bodyStart === false || $bodyEnd === false) {
            return self::fatal('no <tbody> inside #mainTable');
        }

        $body = substr($html, $bodyStart + strlen('<tbody>'), $bodyEnd - $bodyStart - strlen('<tbody>'));

        if (str_contains($body, 'No more available Units!')) {
            return ['inventory_state' => 'no_available_units', 'available_count' => 0, 'requirements_days' => null, 'units' => [], 'errors' => []];
        }

        // Top-level rows start "<tr><td>" literally; the nested tooltip tables
        // (financing blocks) start "<tr>\n<td class=" or "<tr>\n<td style=",
        // never "<tr><td>", so splitting on that exact literal isolates rows
        // without the nested tables breaking the split.
        $rows = array_values(array_filter(
            preg_split('/(?=<tr><td>)/', $body) ?: [],
            fn (string $chunk): bool => str_starts_with($chunk, '<tr><td>'),
        ));

        preg_match_all('/ProjectUnitID=(\d+)/', $body, $idMatches);
        $distinctIds = array_unique($idMatches[1]);

        if ($rows === [] || count($rows) !== count($distinctIds)) {
            return self::fatal(sprintf(
                'expected one row per reservation link, found %d row(s) but %d distinct id(s)',
                count($rows), count($distinctIds),
            ));
        }

        $typeMap = self::UNIT_TYPES[$slug] ?? [];
        $units = [];
        $errors = [];
        $requirementsDays = null;

        foreach ($rows as $row) {
            [$unit, $rowErrors, $days] = self::parseRow($row, $typeMap);
            $errors = [...$errors, ...$rowErrors];
            $requirementsDays ??= $days;
            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        usort($units, fn (array $a, array $b): int => self::sortKey($a) <=> self::sortKey($b));

        return [
            'inventory_state' => 'listed',
            'available_count' => count($units),
            'requirements_days' => $requirementsDays,
            'units' => $units,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $typeMap
     * @return array{0: array<string, mixed>|null, 1: array<int, string>, 2: int|null}
     */
    private static function parseRow(string $row, array $typeMap): array
    {
        $pattern = '/^<tr><td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*)<\/td>\s*<\/tr>\s*$/s';
        if (! preg_match($pattern, $row, $m)) {
            return [null, ['a unit row did not match the expected 6-column shape'], null];
        }
        [, $modelRaw, $codeRaw, $areaRaw, $typeRaw, $priceRaw, $tooltip] = $m;

        $clean = fn (string $s): string => trim(html_entity_decode(strip_tags($s)));
        $model = $clean($modelRaw);
        $code = $clean($codeRaw);
        $rawType = $clean($typeRaw);
        $area = self::toFloat($clean($areaRaw));
        $price = self::toFloat($clean($priceRaw));

        $projectUnitId = preg_match('/ProjectUnitID=(\d+)/', $tooltip, $pm) ? (int) $pm[1] : null;
        if ($projectUnitId === null) {
            return [null, ["row for code \"{$code}\" has no ProjectUnitID"], null];
        }

        $errors = [];
        $typeKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $rawType) ?? '');
        $unitType = $typeMap[$typeKey] ?? null;
        if ($unitType === null) {
            $errors[] = "unit #{$projectUnitId} (\"{$code}\") has an unrecognised Unit Type \"{$rawType}\" — dropped, not guessed";

            return [null, $errors, null];
        }

        $totalPackage = preg_match('/Total Package:\s*([\d,.]+)/', $tooltip, $tp) ? self::toFloat($tp[1]) : null;
        $reservationFee = preg_match('/Reservation Fee:\s*([\d,.]+)/', $tooltip, $rf) ? self::toFloat($rf[1]) : null;
        $requirementsDays = preg_match('/complied within <b>(\d+)<\/b> Days/', $tooltip, $rd) ? (int) $rd[1] : null;

        $financing = [];
        if (preg_match_all('/colspan="2">\s*((?:HDMF|Bank|In[- ]House) Financing)\s*<\/td>(.*?)<\/table>/s', $tooltip, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $block) {
                $financing[] = self::parseFinancing($block[1], $block[2]);
            }
        }

        [$building, $floorCode, $floor, $unitNumber, $block, $lot] = self::splitCode($code, $errors);

        $unit = [
            'project_unit_id' => $projectUnitId,
            'code' => $code,
            'name' => self::nameFor($building, $unitNumber, $block, $lot, $code),
            'model' => $model,
            'raw_type' => $rawType,
            'unit_type' => $unitType,
            'building' => $building,
            'floor_code' => $floorCode,
            'floor' => $floor,
            'unit_number' => $unitNumber,
            'block' => $block,
            'lot' => $lot,
            'area_sqm' => self::numeric($area),
            'price' => $price !== null ? (int) round($price) : null,
            'total_package' => self::numeric($totalPackage),
            'reservation_fee' => self::numeric($reservationFee),
            'financing' => $financing,
            'status' => 'available',
        ];

        return [$unit, $errors, $requirementsDays];
    }

    /**
     * @return array{
     *     option: string,
     *     discount: int|float|null,
     *     required_income: int|float|null,
     *     loanable_amount: int|float|null,
     *     monthly_amortization: int|float|null,
     *     equity_months: int|null,
     *     equity_total: int|float|null,
     *     equity_monthly: int|float|null,
     * }
     */
    private static function parseFinancing(string $option, string $block): array
    {
        $discount = preg_match('/Discount:.*?_cell-right">\s*([\d,.]+)/s', $block, $m) ? self::toFloat($m[1]) : null;
        $income = preg_match('/Required Income<\/td>\s*<td class="_cell-right">\s*([\d,.]+)/s', $block, $m) ? self::toFloat($m[1]) : null;
        $loanable = preg_match('/Loanable Amount<\/td>\s*<td class="_cell-right">\s*([\d,.]+)/s', $block, $m) ? self::toFloat($m[1]) : null;
        $amort = preg_match('/Est\. Monthly Amort<\/td>\s*<td class="_cell-right">\s*([\d,.]+)/s', $block, $m) ? self::toFloat($m[1]) : null;
        $months = preg_match('/Equity&(?:amp;)?MF @ (\d+) mo\./', $block, $m) ? (int) $m[1] : null;
        $total = preg_match('/Equity&(?:amp;)?MF @ \d+ mo\.<\/b><\/td>\s*<td class="_cell-right">\s*([\d,.]+)/s', $block, $m) ? self::toFloat($m[1]) : null;
        $monthly = preg_match('/\d+(?:st|nd|rd|th)-\d+(?:st|nd|rd|th)\s*<\/td>\s*<td class="_cell-right">\s*([\d,.]+)/s', $block, $m)
            ? self::toFloat($m[1])
            : ($months !== null && $total !== null && $months > 0 ? round($total / $months, 2) : null);

        return [
            'option' => $option,
            'discount' => self::numeric($discount),
            'required_income' => self::numeric($income),
            'loanable_amount' => self::numeric($loanable),
            'monthly_amortization' => self::numeric($amort),
            'equity_months' => $months,
            'equity_total' => self::numeric($total),
            'equity_monthly' => self::numeric($monthly),
        ];
    }

    /**
     * Building-Floor(Unit) → Bldg {letter} · Unit {n}, e.g. "J-GF (101)" or
     * "T-10F (1037)". Block-Lot → Block {b} · Lot {l}, e.g. "24-27". Neither
     * shape → the raw code, with an error so it's easy to find and extend.
     *
     * @param  array<int, string>  &$errors
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: int|null, 4: string|null, 5: string|null}
     */
    private static function splitCode(string $code, array &$errors): array
    {
        if (preg_match('/^([A-Z])-(GF|(\d+)F)\s*\((\d+)\)$/', $code, $m)) {
            $floorCode = $m[2];
            $floor = $floorCode === 'GF' ? 'Ground floor' : Number::ordinal((int) $m[3]).' floor';

            return [$m[1], $floorCode, $floor, (int) $m[4], null, null];
        }

        if (preg_match('/^(\d+)-(\d+)$/', $code, $m)) {
            return [null, null, null, null, $m[1], $m[2]];
        }

        $errors[] = "unit code \"{$code}\" doesn't match a known Building-Floor(Unit) or Block-Lot pattern";

        return [null, null, null, null, null, null];
    }

    private static function nameFor(?string $building, ?int $unitNumber, ?string $block, ?string $lot, string $code): string
    {
        if ($building !== null && $unitNumber !== null) {
            return "Bldg {$building} · Unit {$unitNumber}";
        }
        if ($block !== null && $lot !== null) {
            return "Block {$block} · Lot {$lot}";
        }

        return "Unit {$code}";
    }

    /** A single string key so mixed building/block schemes never collide in a type-unsafe comparison. */
    private static function sortKey(array $unit): string
    {
        if ($unit['building'] !== null) {
            return sprintf('A-%s-%06d', $unit['building'], $unit['unit_number']);
        }

        return sprintf('B-%06d-%06d', (int) $unit['block'], (int) $unit['lot']);
    }

    private static function toFloat(string $raw): ?float
    {
        $clean = trim(preg_replace('/[^0-9.]/', '', $raw) ?? '');

        return $clean === '' ? null : round((float) $clean, 2);
    }

    /** Keeps the committed JSON readable: a whole number stays "24", not "24.0". */
    private static function numeric(?float $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return floor($value) === $value ? (int) $value : $value;
    }

    /**
     * @return array{inventory_state: "error", available_count: 0, requirements_days: null, units: array{}, errors: array<int, string>}
     */
    private static function fatal(string $message): array
    {
        return ['inventory_state' => 'error', 'available_count' => 0, 'requirements_days' => null, 'units' => [], 'errors' => [$message]];
    }
}
