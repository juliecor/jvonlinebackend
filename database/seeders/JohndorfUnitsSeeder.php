<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Per-unit inventory from data/johndorf-units.json (php artisan johndorf:scrape-units),
 * matched against the house models JohndorfProjectsSeeder::publicPages() already
 * seeded. Chained from the end of JohndorfProjectsSeeder::run() — run on its own
 * before that has happened at least once and every project is skipped, for
 * having no house model to match a unit's unit_type against.
 *
 * Idempotent and re-run safe:
 *  - a unit this seeder creates carries a provenance marker at the start of its
 *    notes ("Johndorf inventory #<id> · seeded <status> <date> · …"); only a
 *    unit carrying that marker is ever matched, updated or deleted here — one a
 *    staff member typed in by hand is never touched, beyond being adopted (not
 *    duplicated) the one time its name happens to collide with a scraped unit's;
 *  - a unit that drops off Johndorf's public list moves to "reserved", not
 *    "sold" — Johndorf's own reservation flow holds a unit the moment someone
 *    starts reserving it online, and a hold can lapse back within about a
 *    week — and moves back to "available" the moment it reappears;
 *  - if staff changed a seeded unit's status by hand (e.g. to "sold"), this
 *    seeder leaves it alone from then on and only warns; it never overwrites
 *    a human call with what Johndorf's site currently shows.
 */
class JohndorfUnitsSeeder extends Seeder
{
    private const MARKER = '/^Johndorf inventory #(\d+) · seeded (available|reserved) (\d{4}-\d{2}-\d{2})/';

    private const NO_INVENTORY_SUFFIX = '/ · Johndorf\'s online reservation inventory listed no available units on \d{4}-\d{2}-\d{2} \(RealtyID \d+\)\.$/';

    public function __construct(private readonly ?string $file = null) {}

    public function run(): void
    {
        $realty = Realty::where('slug', 'johndorf')->first();
        if (! $realty) {
            $this->command->error('Run DatabaseSeeder first: the Johndorf realty is missing.');

            return;
        }

        $path = $this->file ?? __DIR__.'/data/johndorf-units.json';
        $doc = json_decode(file_get_contents($path), true);
        $today = now()->toDateString();

        foreach ($doc['projects'] as $row) {
            $project = Project::where('realty_id', $realty->id)
                ->where(fn ($q) => $q->where('slug', $row['slug'])->orWhere('name', $row['name']))
                ->first();

            if (! $project) {
                $this->command->warn("  {$row['slug']}: no matching project for this realty — skipped");

                continue;
            }

            if ($row['inventory_state'] === 'error') {
                $this->command->warn("  {$row['slug']}: last scrape failed ({$row['error']}) — left untouched");

                continue;
            }

            if ($project->status === 'archived' && $row['units'] !== []) {
                $this->command->warn("  {$row['slug']}: archived, but Johndorf lists units for it online");
            }

            if ($row['inventory_state'] === 'no_available_units') {
                $this->noteNoInventory($project, $row, $today);

                continue;
            }

            $this->seedProject($realty, $project, $row, $today);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function seedProject(Realty $realty, Project $project, array $row, string $today): void
    {
        $typeNames = UnitType::where('project_id', $project->id)->pluck('name')->all();
        $missing = array_values(array_unique(array_diff(array_column($row['units'], 'unit_type'), $typeNames)));
        if ($missing !== []) {
            $this->command->warn("  {$row['slug']}: unit type(s) not seeded yet [".implode(', ', $missing).'] — run JohndorfProjectsSeeder first, skipped');

            return;
        }

        DB::transaction(function () use ($realty, $project, $row, $today) {
            $units = Unit::where('project_id', $project->id)->get();
            $byMarkerId = [];
            foreach ($units as $unit) {
                if ($marker = $this->marker($unit->notes)) {
                    $byMarkerId[$marker['id']] = $unit;
                }
            }
            $byName = $units->keyBy('name');

            $seen = [];
            $created = $updated = $relisted = $warned = 0;

            foreach ($row['units'] as $u) {
                $seen[] = $u['project_unit_id'];
                $existing = $byMarkerId[$u['project_unit_id']] ?? $byName->get($u['name']);
                $marker = $existing ? $this->marker($existing->notes) : null;

                if ($existing && $marker && $marker['status'] !== $existing->status) {
                    $warned++;
                    $this->command->warn("  {$row['slug']}: unit #{$u['project_unit_id']} ({$u['name']}) was hand-set to \"{$existing->status}\" (we last set \"{$marker['status']}\") — left alone");

                    continue;
                }

                $wasReserved = $existing?->status === 'reserved';
                $unit = $existing ?? new Unit(['project_id' => $project->id]);
                $unit->fill([
                    'realty_id' => $realty->id,
                    'name' => $u['name'],
                    'unit_type' => $u['unit_type'],
                    'category' => 'Residential',
                    'floor' => $u['floor'],
                    'area_sqm' => $u['area_sqm'],
                    'price' => $u['price'],
                    'status' => 'available',
                    'notes' => $this->noteFor($u, $row, 'available', $today),
                ]);
                if (! $existing || $this->buyerNotesAreOurs($existing->buyer_notes)) {
                    $unit->buyer_notes = $this->buyerNoteFor($u);
                }
                $unit->save();

                if (! $existing) {
                    $created++;
                } elseif ($wasReserved) {
                    $relisted++;
                } else {
                    $updated++;
                }
            }

            $delisted = $this->delistVanished($byMarkerId, $seen, $today);
            $removed = $this->removePlaceholders($project, $row['units'] !== []);

            $summary = "{$created} created, {$updated} updated, {$relisted} re-listed, {$delisted} delisted, {$removed} placeholder(s) removed";
            $this->command->info("  {$row['slug']}: {$summary}".($warned ? ", {$warned} left alone (hand-edited)" : ''));
        });
    }

    /**
     * @param  array<int, Unit>  $byMarkerId  keyed by project_unit_id
     * @param  array<int, int>  $seen  project_unit_id values still listed this run
     */
    private function delistVanished(array $byMarkerId, array $seen, string $today): int
    {
        $delisted = 0;
        foreach ($byMarkerId as $projectUnitId => $unit) {
            if (in_array($projectUnitId, $seen, true)) {
                continue; // still listed — handled above.
            }
            $marker = $this->marker($unit->notes);
            if ($marker['status'] !== $unit->status || $marker['status'] !== 'available') {
                continue; // staff already changed it, or we already delisted it on an earlier run.
            }
            $unit->status = 'reserved';
            $unit->notes = preg_replace(self::MARKER, "Johndorf inventory #{$projectUnitId} · seeded reserved {$today}", $unit->notes, 1)
                ." · missing from Johndorf's available list since {$today}; confirm with Johndorf.";
            $unit->save();
            $delisted++;
        }

        return $delisted;
    }

    /** Only for a project that received ≥1 real unit this run — the other 16 keep their house-model rows untouched. */
    private function removePlaceholders(Project $project, bool $receivedRealUnits): int
    {
        if (! $receivedRealUnits) {
            return 0;
        }

        $typeNames = UnitType::where('project_id', $project->id)->pluck('name')->all();
        $removed = 0;

        Unit::where('project_id', $project->id)
            ->whereColumn('name', 'unit_type')
            ->whereIn('name', $typeNames)
            ->get()
            ->each(function (Unit $unit) use (&$removed) {
                if ($this->marker($unit->notes) !== null) {
                    return; // a real seeded unit, not a leftover placeholder.
                }
                if ($unit->offers()->exists()) {
                    $this->command->warn("  placeholder \"{$unit->name}\" has an offer — kept; mark it sold or reserved by hand");

                    return;
                }
                $unit->delete();
                $removed++;
            });

        return $removed;
    }

    /** @param  array<string, mixed>  $row */
    private function noteNoInventory(Project $project, array $row, string $today): void
    {
        $suffix = " · Johndorf's online reservation inventory listed no available units on {$today} (RealtyID {$row['realty_id']}).";

        Unit::where('project_id', $project->id)
            ->whereColumn('name', 'unit_type')
            ->get()
            ->each(function (Unit $unit) use ($suffix) {
                if ($this->marker($unit->notes) !== null) {
                    return;
                }
                $base = preg_replace(self::NO_INVENTORY_SUFFIX, '', (string) $unit->notes);
                $unit->update(['notes' => $base.$suffix]);
            });
    }

    /** @return array{id: int, status: string, date: string}|null */
    private function marker(?string $notes): ?array
    {
        if ($notes !== null && preg_match(self::MARKER, $notes, $m)) {
            return ['id' => (int) $m[1], 'status' => $m[2], 'date' => $m[3]];
        }

        return null;
    }

    /** @param  array<string, mixed>  $u  @param  array<string, mixed>  $row */
    private function noteFor(array $u, array $row, string $status, string $date): string
    {
        $parts = [
            "Johndorf inventory #{$u['project_unit_id']} · seeded {$status} {$date}",
            $u['code'],
            $u['model'],
            'package ₱'.number_format((float) $u['price']),
            'RF ₱'.number_format((float) $u['reservation_fee']),
        ];

        foreach ($u['financing'] as $f) {
            $bits = ["{$f['option']}:"];
            if ($f['discount'] !== null) {
                $bits[] = 'discount ₱'.number_format((float) $f['discount']).',';
            }
            $bits[] = 'equity ₱'.number_format((float) $f['equity_total'])." over {$f['equity_months']} mo (₱".number_format((float) $f['equity_monthly']).'/mo),';
            $bits[] = 'loan ₱'.number_format((float) $f['loanable_amount']).',';
            $bits[] = 'est. ₱'.number_format((float) $f['monthly_amortization'], 2).'/mo,';
            $bits[] = 'income ₱'.number_format((float) $f['required_income']);
            $parts[] = implode(' ', $bits);
        }

        $parts[] = "source RealtyID {$row['realty_id']}";
        if ($row['slug'] === 'tierranava-carcar') {
            $parts[] = "Unit Area {$u['area_sqm']} sqm as listed (lot area; the model's usable floor area is 54 sqm)";
        }

        return implode(' · ', $parts);
    }

    /** @param  array<string, mixed>  $u */
    private function buyerNoteFor(array $u): string
    {
        $prefix = match ($u['raw_type']) {
            'inner' => 'Inner unit · ',
            'end' => 'End unit · ',
            'corner' => 'Corner unit · ',
            default => '',
        };
        $hasHdmf = collect($u['financing'])->contains(fn ($f) => str_starts_with($f['option'], 'HDMF'));
        $hasBank = collect($u['financing'])->contains(fn ($f) => str_starts_with($f['option'], 'Bank'));
        $financing = match (true) {
            $hasHdmf && $hasBank => 'Pag-IBIG (HDMF) or bank financing',
            $hasHdmf => 'Pag-IBIG (HDMF) financing',
            $hasBank => 'Bank financing',
            default => 'financing on request',
        };
        $months = $u['financing'][0]['equity_months'] ?? null;

        return sprintf('%sReservation fee ₱%s · Equity%s · %s',
            $prefix,
            number_format((float) $u['reservation_fee']),
            $months ? " over {$months} months" : '',
            $financing,
        );
    }

    /** Staff text in buyer_notes survives a re-seed; only what the seeder itself wrote gets refreshed. */
    private function buyerNotesAreOurs(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return true;
        }

        foreach (['Reservation fee', 'Inner unit', 'End unit', 'Corner unit'] as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
