<?php

namespace App\Console\Commands;

use App\Support\JohndorfInventory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Fetches Johndorf's own public reservation inventory (not an export they
 * handed us) and writes database/seeders/data/johndorf-units.json, which
 * JohndorfUnitsSeeder then reads. Review `git diff` on that file before
 * seeding — a scrape is a draft of what to seed, not the seed itself.
 *
 * Only GETs the inventory *listing*; never requests
 * project_unit_list_transition.php, which is a step in Johndorf's live
 * reservation flow.
 */
#[Signature('johndorf:scrape-units
    {--project=* : Limit to these project slugs (default: all mapped projects)}
    {--out= : Write to this path instead of database/seeders/data/johndorf-units.json}
    {--dry-run : Print the summary only; write nothing}
')]
#[Description("Scrape Johndorf's online reservation inventory into johndorf-units.json")]
class ScrapeJohndorfUnits extends Command
{
    /**
     * Dashboard project slug → [Johndorf's internal RealtyID, the legacy
     * site's inventory page title for that project]. Found by probing
     * https://johndorfventures.com/reservation/project_unit_list.php?RealtyID=1..130
     * on 2026-10-08; kept here rather than guessed at scrape time because
     * nothing on either site publishes this mapping.
     *
     * @var array<string, array{int, string}>
     */
    private const REALTY_IDS = [
        'plumera' => [41, 'Plumera'],
        'tierranava-carcar' => [40, 'Tierra Nava'],
        'montierra' => [30, 'Montierra'],
        'evissa-lapu-lapu' => [31, 'Evissa Cebu'],
        'evissa-davao' => [33, 'Evissa Davao'],
        'navona-davao' => [34, 'Navona Davao'],
        'astana-davao' => [35, 'Astana Davao'],
        'navona-lumbia' => [38, 'Navona Lumbia'],
        'mimosa-cebu' => [42, 'Mimosa Labangon'],
        'mimosa-minglanilla' => [43, 'Mimosa Minglanilla'],
        'coral-village' => [66, 'Coral Village'],
        'pich-4b' => [70, 'PICH4B'],
        'villa-castena' => [73, 'Villa Castena Iligan'],
        'tierranava-lumbia' => [77, 'Tierra Nava Lumbia'],
        'tierranava-opol' => [84, 'Tierra Nava Opol'],
        'navona-court' => [86, 'Navona Court'],
        'tierranava-tagoloan' => [87, 'Tierra Nava Tagoloan'],
        'arvesa-village' => [89, 'Arvesa Village'],
    ];

    private const SOURCE_PATTERN = 'https://johndorfventures.com/reservation/project_unit_list.php';

    public function handle(): int
    {
        $outPath = $this->option('out') ?: __DIR__.'/../../../database/seeders/data/johndorf-units.json';
        $wanted = $this->option('project');
        $slugs = $wanted !== [] ? array_intersect_key(self::REALTY_IDS, array_flip($wanted)) : self::REALTY_IDS;

        if ($slugs === []) {
            $this->error('No known project slug matched --project.');

            return self::INVALID;
        }

        $names = $this->projectNames();
        $previous = $this->previousOutput($outPath);

        $rows = [];
        $rowsByRow = [];
        $anyFailed = false;
        $i = 0;

        foreach ($slugs as $slug => [$realtyId, $inventoryTitle]) {
            if ($i > 0) {
                Sleep::for(1)->seconds();
            }
            $i++;

            $url = self::SOURCE_PATTERN;
            $scrapedAt = now()->toIso8601String();

            try {
                $response = Http::withUserAgent('jvconline inventory sync')
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->retry(2, 1000)
                    ->get($url, ['RealtyID' => $realtyId])
                    ->throw();

                $parsed = JohndorfInventory::parse($response->body(), $slug, $inventoryTitle);
            } catch (\Throwable $e) {
                $parsed = ['inventory_state' => 'error', 'available_count' => 0, 'requirements_days' => null, 'units' => [], 'errors' => [$e->getMessage()]];
            }

            if ($parsed['inventory_state'] === 'error') {
                $anyFailed = true;
                if (isset($previous[$slug])) {
                    // Keep the last good scrape rather than overwrite it with a failure.
                    $rows[$slug] = $previous[$slug];
                    $rowsByRow[] = [$slug, 'kept previous', $previous[$slug]['available_count'], implode('; ', $parsed['errors'])];

                    continue;
                }
            }

            $rows[$slug] = [
                'slug' => $slug,
                'name' => $names[$slug] ?? $inventoryTitle,
                'realty_id' => $realtyId,
                'inventory_title' => $inventoryTitle,
                'source_url' => $url.'?RealtyID='.$realtyId,
                'scraped_at' => $scrapedAt,
                'inventory_state' => $parsed['inventory_state'],
                'available_count' => $parsed['available_count'],
                'requirements_days' => $parsed['requirements_days'],
                'error' => $parsed['inventory_state'] === 'error' ? ($parsed['errors'][0] ?? 'unknown error') : null,
                'units' => $parsed['units'],
            ];
            $rowsByRow[] = [$slug, $parsed['inventory_state'], $parsed['available_count'], implode('; ', $parsed['errors'])];
        }

        // Projects not touched this run (narrowed by --project) keep whatever was already on disk.
        foreach ($previous as $slug => $row) {
            if (! isset($rows[$slug])) {
                $rows[$slug] = $row;
            }
        }
        ksort($rows);

        $this->table(['Slug', 'State', 'Units', 'Notes'], $rowsByRow);

        if ($this->option('dry-run')) {
            $this->info('--dry-run: nothing written.');

            return $anyFailed ? self::FAILURE : self::SUCCESS;
        }

        $document = [
            'generated_by' => 'php artisan johndorf:scrape-units',
            'generated_at' => now()->toIso8601String(),
            'source_pattern' => self::SOURCE_PATTERN.'?RealtyID={id}',
            'projects' => array_values($rows),
        ];

        file_put_contents(
            $outPath,
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->info(($anyFailed ? 'Finished with errors. ' : 'Done. ')."Wrote {$outPath}.");

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, string> project slug => project name, from the same JSON the projects seeder reads. */
    private function projectNames(): array
    {
        $path = __DIR__.'/../../../database/seeders/data/johndorf-projects.json';
        $rows = json_decode(file_get_contents($path), true) ?? [];

        return array_column($rows, 'name', 'slug');
    }

    /** @return array<string, array<string, mixed>> keyed by slug, or [] if there's nothing to fall back on yet. */
    private function previousOutput(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), true);

        return array_column($decoded['projects'] ?? [], null, 'slug');
    }
}
