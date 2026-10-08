<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** php artisan johndorf:scrape-units — hits Johndorf's live inventory page, so every HTTP call here is faked. */
class ScrapeJohndorfUnitsCommandTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/johndorf/{$name}"));
    }

    private function outPath(): string
    {
        return sys_get_temp_dir().'/johndorf-units-test-'.str_replace('.', '', uniqid('', true)).'.json';
    }

    public function test_scrapes_the_requested_projects_into_one_json_file(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match (true) {
                str_contains($request->url(), 'RealtyID=41') => Http::response($this->fixture('inventory-41-sample.html')),
                str_contains($request->url(), 'RealtyID=40') => Http::response($this->fixture('inventory-40.html')),
                str_contains($request->url(), 'RealtyID=43') => Http::response($this->fixture('inventory-43.html')),
                default => Http::response('unexpected RealtyID', 500),
            };
        });

        $out = $this->outPath();

        $this->artisan('johndorf:scrape-units', [
            '--project' => ['plumera', 'tierranava-carcar', 'mimosa-minglanilla'],
            '--out' => $out,
        ])->assertSuccessful();

        $this->assertFileExists($out);
        $doc = json_decode(file_get_contents($out), true);

        $this->assertSame('php artisan johndorf:scrape-units', $doc['generated_by']);
        $this->assertCount(3, $doc['projects']);
        // ksort by slug: mimosa-minglanilla, plumera, tierranava-carcar.
        $this->assertSame(['mimosa-minglanilla', 'plumera', 'tierranava-carcar'], array_column($doc['projects'], 'slug'));

        $bySlug = array_column($doc['projects'], null, 'slug');
        $this->assertSame('no_available_units', $bySlug['mimosa-minglanilla']['inventory_state']);
        $this->assertSame(0, $bySlug['mimosa-minglanilla']['available_count']);
        $this->assertSame('listed', $bySlug['plumera']['inventory_state']);
        $this->assertSame(7, $bySlug['plumera']['available_count']);
        $this->assertSame('listed', $bySlug['tierranava-carcar']['inventory_state']);
        $this->assertSame(2, $bySlug['tierranava-carcar']['available_count']);
        $this->assertSame('Block 24 · Lot 27', $bySlug['tierranava-carcar']['units'][0]['name']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'transition'));

        @unlink($out);
    }

    public function test_a_failed_project_keeps_its_previous_entry_and_exits_with_failure(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['*RealtyID=43*' => Http::response('Service Unavailable', 503)]);

        $out = $this->outPath();
        $previousEntry = [
            'slug' => 'mimosa-minglanilla',
            'name' => 'Mimosa Minglanilla',
            'realty_id' => 43,
            'inventory_title' => 'Mimosa Minglanilla',
            'source_url' => 'https://johndorfventures.com/reservation/project_unit_list.php?RealtyID=43',
            'scraped_at' => '2026-10-01T00:00:00+00:00',
            'inventory_state' => 'no_available_units',
            'available_count' => 0,
            'requirements_days' => null,
            'error' => null,
            'units' => [],
        ];
        file_put_contents($out, json_encode([
            'generated_by' => 'php artisan johndorf:scrape-units',
            'generated_at' => '2026-10-01T00:00:00+00:00',
            'source_pattern' => 'https://johndorfventures.com/reservation/project_unit_list.php?RealtyID={id}',
            'projects' => [$previousEntry],
        ]));

        // Johndorf's site errors on every attempt. The entry already on disk must survive, byte for byte.
        $this->artisan('johndorf:scrape-units', ['--project' => ['mimosa-minglanilla'], '--out' => $out])->assertFailed();

        $after = json_decode(file_get_contents($out), true)['projects'][0];
        $this->assertSame($previousEntry, $after);

        @unlink($out);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['*RealtyID=43*' => Http::response($this->fixture('inventory-43.html'))]);

        $out = $this->outPath();
        $this->assertFileDoesNotExist($out);

        $this->artisan('johndorf:scrape-units', [
            '--project' => ['mimosa-minglanilla'],
            '--out' => $out,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($out);
    }
}
