<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Realty;
use App\Models\SiteRow;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A site map drawn on one server (the admin's computer) is saved with site-map:export and drawn the
 * same on another (the live site) with site-map:import: rows, lot numbers and each lot's details.
 */
class SiteMapTransferTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $johndorf = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $johndorf->id]));
        $this->project = Project::create(['realty_id' => $johndorf->id, 'name' => 'Astana Davao', 'slug' => 'astana-davao', 'status' => 'active', 'is_public' => true,
            'site_plan_paths' => ['https://cdn.example.com/astana/plan-1.jpg']]);
        $this->file = tempnam(sys_get_temp_dir(), 'site-map');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_a_saved_map_is_drawn_the_same_on_another_server_and_only_once(): void
    {
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '4', 'corners' => [[0.1, 0.2], [0.5, 0.2], [0.5, 0.3], [0.1, 0.3]], 'first_lot' => 1, 'lot_count' => 3, 'unit_type' => 'Two-Storey Townhouse', 'lot_area_sqm' => 50, 'area_sqm' => 48])->assertCreated();
        $bent = [[0.1, 0.6], [0.5, 0.6], [0.5, 0.9], [0.4, 0.9], [0.4, 0.7], [0.1, 0.7]];
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '5', 'corners' => $bent, 'first_lot' => 16, 'lot_count' => 2])->assertCreated();
        Unit::where('block', '4')->where('lot', '1')->firstOrFail()->update(['lot_area_sqm' => 75, 'tags' => ['corner'], 'price' => 2500000, 'status' => 'sold']);

        $this->artisan('site-map:export', ['project' => 'astana-davao', '--file' => $this->file])->expectsOutputToContain('Saved 2 rows, 5 lots')->assertExitCode(0);
        $saved = json_decode((string) file_get_contents($this->file), true);
        $this->assertSame('https://cdn.example.com/astana/plan-1.jpg', $saved['image']);
        $this->assertSame(['index' => 0, 'lot' => '1', 'unit_type' => 'Two-Storey Townhouse', 'lot_area_sqm' => 75, 'area_sqm' => 48, 'price' => 2500000, 'tags' => ['corner']], $saved['rows'][0]['lots'][0]);

        // The other server has the project, but nothing drawn yet.
        Unit::query()->delete();
        SiteRow::query()->delete();
        $this->artisan('site-map:import', ['project' => 'astana-davao', '--file' => $this->file])->expectsOutputToContain('Drew 2 rows (5 lots) on Astana Davao.')->assertExitCode(0);

        $this->assertSame([4, 6], SiteRow::orderBy('id')->get()->map(fn (SiteRow $r) => count($r->corners))->all());
        $corner = Unit::where('block', '4')->where('lot', '1')->firstOrFail();
        $this->assertSame(['Block 4 · Lot 1', '75.00', '48.00', '2500000.00', ['corner'], 'available'], [$corner->name, $corner->lot_area_sqm, $corner->area_sqm, $corner->price, $corner->tags, $corner->status]);
        $this->assertSame('50.00', Unit::where('block', '4')->where('lot', '2')->value('lot_area_sqm'));
        $this->assertSame(['16', '17'], Unit::where('block', '5')->orderBy('row_index')->pluck('lot')->all());

        $this->artisan('site-map:import', ['project' => 'astana-davao', '--file' => $this->file])->expectsOutputToContain('Drew 0 rows (0 lots) on Astana Davao; 2 rows were already on the map.')->assertExitCode(0);
        $this->assertSame(2, SiteRow::count());
        $this->assertSame(5, Unit::count());

        // --update: a corrected file brings sizes and tags up to date on the live map, without touching prices or statuses.
        $saved = json_decode((string) file_get_contents($this->file), true);
        $saved['rows'][0]['lots'][1]['tags'] = ['corner'];
        $saved['rows'][0]['lots'][1]['lot_area_sqm'] = 60;
        $saved['rows'][0]['lots'][1]['price'] = 1;
        file_put_contents($this->file, json_encode($saved));
        Unit::where('block', '4')->where('lot', '2')->update(['status' => 'reserved']);
        $this->artisan('site-map:import', ['project' => 'astana-davao', '--file' => $this->file, '--update' => true])->expectsOutputToContain('1 lots already on the map were brought up to date')->assertExitCode(0);
        $lot2 = Unit::where('block', '4')->where('lot', '2')->firstOrFail();
        $this->assertSame([['corner'], '60.00', '2500000.00', 'reserved'], [$lot2->tags, $lot2->lot_area_sqm, Unit::where('block', '4')->where('lot', '1')->value('price'), $lot2->status]);
        $this->assertNull($lot2->price);
    }

    public function test_a_dry_run_saves_nothing_and_bad_rows_are_reported(): void
    {
        file_put_contents($this->file, json_encode(['image' => 'https://cdn.example.com/astana/plan-1.jpg', 'rows' => [
            ['block' => '1', 'first_lot' => 2, 'lot_count' => 2, 'corners' => [[0.1, 0.2], [0.5, 0.2], [0.5, 0.3], [0.1, 0.3]], 'lots' => [['index' => 0, 'lot_area_sqm' => 86]]],
            ['block' => '2', 'first_lot' => 2, 'lot_count' => 2, 'corners' => [[0.1, 0.2], [0.3, 0.2], [0.5, 0.2], [0.5, 0.3], [0.1, 0.3]], 'lots' => []],
        ]]));

        $this->artisan('site-map:import', ['project' => 'astana-davao', '--file' => $this->file, '--dry-run' => true])
            ->expectsOutputToContain('Dry run, nothing saved. Would draw 1 rows (2 lots)')
            ->expectsOutputToContain('Row 2 (Block 2): Every point on the road side needs one straight across from it at the back.')
            ->assertExitCode(1);
        $this->assertSame(0, SiteRow::count());
        $this->assertSame(0, Unit::count());

        $this->artisan('site-map:import', ['project' => 'nowhere', '--file' => $this->file])->expectsOutputToContain('No project nowhere for johndorf.')->assertExitCode(1);
        $this->artisan('site-map:export', ['project' => 'astana-davao', '--file' => $this->file])->expectsOutputToContain('has no lots on its site map yet')->assertExitCode(1);
    }
}
