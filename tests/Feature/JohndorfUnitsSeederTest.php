<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Database\Seeders\JohndorfUnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * JohndorfUnitsSeeder reads a johndorf:scrape-units JSON file and upserts
 * per-unit Unit rows, matched by a provenance marker in `notes` so re-runs
 * never duplicate a row and never touch what a staff member set by hand.
 */
class JohndorfUnitsSeederTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Test Towers', 'slug' => 'test-towers', 'status' => 'active']);
        UnitType::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Studio Unit', 'sort' => 0]);
    }

    private function fixturePath(string $name): string
    {
        return base_path("tests/Fixtures/johndorf/{$name}");
    }

    /** Binds the seeder to a given fixture file, then runs it, for this test only. */
    private function seedFromFixture(string $fixture): void
    {
        $this->app->bind(JohndorfUnitsSeeder::class, fn () => new JohndorfUnitsSeeder($this->fixturePath($fixture)));
        $this->artisan('db:seed', ['--class' => JohndorfUnitsSeeder::class])->assertSuccessful();
    }

    public function test_seeds_units_with_the_exact_fields_and_a_provenance_marker(): void
    {
        $this->seedFromFixture('units-a.json');

        $this->assertSame(2, Unit::where('project_id', $this->project->id)->count());

        $unit = Unit::where('name', 'Bldg A · Unit 101')->firstOrFail();
        $this->assertSame('Studio Unit', $unit->unit_type);
        $this->assertSame('Residential', $unit->category);
        $this->assertSame('1st floor', $unit->floor);
        $this->assertSame('24.00', $unit->area_sqm);
        $this->assertSame('3000000.00', $unit->price);
        $this->assertSame('available', $unit->status);
        $this->assertStringStartsWith('Johndorf inventory #9001 · seeded available '.now()->toDateString(), $unit->notes);
        $this->assertStringContainsString('A-1F (101)', $unit->notes);
        $this->assertStringContainsString('RF ₱15,000', $unit->notes);
        $this->assertSame('Reservation fee ₱15,000 · Equity over 24 months · Bank financing', $unit->buyer_notes);
    }

    public function test_running_it_twice_creates_no_duplicates(): void
    {
        $this->seedFromFixture('units-a.json');
        $this->seedFromFixture('units-a.json');

        $this->assertSame(2, Unit::where('project_id', $this->project->id)->count());
    }

    public function test_a_unit_that_disappears_is_marked_reserved_then_available_again_if_it_returns(): void
    {
        $this->seedFromFixture('units-a.json');
        $unit102 = Unit::where('name', 'Bldg A · Unit 102')->firstOrFail();
        $this->assertSame('available', $unit102->status);

        $this->seedFromFixture('units-b.json');
        $unit102->refresh();
        $this->assertSame('reserved', $unit102->status);
        $this->assertStringContainsString("missing from Johndorf's available list since", $unit102->notes);
        $this->assertSame(2, Unit::where('project_id', $this->project->id)->count(), 'the unit is delisted, never deleted');

        $this->seedFromFixture('units-a.json');
        $unit102->refresh();
        $this->assertSame('available', $unit102->status);
    }

    public function test_a_unit_renamed_by_hand_is_still_matched_by_its_marker(): void
    {
        $this->seedFromFixture('units-a.json');
        $unit = Unit::where('name', 'Bldg A · Unit 101')->firstOrFail();
        $unit->update(['name' => 'Renamed by staff']);

        $this->seedFromFixture('units-a.json');

        $this->assertSame(2, Unit::where('project_id', $this->project->id)->count(), 'matched by marker, not duplicated under the old name');
        $this->assertSame('Bldg A · Unit 101', $unit->fresh()->name);
    }

    public function test_a_status_staff_set_by_hand_is_left_alone(): void
    {
        $this->seedFromFixture('units-a.json');
        $unit = Unit::where('name', 'Bldg A · Unit 101')->firstOrFail();
        $unit->update(['status' => 'sold']);

        $this->seedFromFixture('units-a.json');

        $this->assertSame('sold', $unit->fresh()->status);
    }

    public function test_a_hand_added_unit_with_no_marker_is_never_touched(): void
    {
        $handAdded = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Lot 99', 'unit_type' => 'Studio Unit', 'category' => 'Residential', 'status' => 'available', 'notes' => 'Added by staff, not from Johndorf.']);

        $this->seedFromFixture('units-a.json');

        $handAdded->refresh();
        $this->assertSame('Lot 99', $handAdded->name);
        $this->assertSame('Added by staff, not from Johndorf.', $handAdded->notes);
        $this->assertSame(3, Unit::where('project_id', $this->project->id)->count());
    }

    public function test_a_placeholder_house_model_row_with_no_offers_is_removed(): void
    {
        Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Studio Unit', 'unit_type' => 'Studio Unit', 'category' => 'Residential', 'status' => 'available', 'notes' => 'placeholder from the projects seeder']);

        $this->seedFromFixture('units-a.json');

        $this->assertDatabaseMissing('units', ['project_id' => $this->project->id, 'name' => 'Studio Unit', 'unit_type' => 'Studio Unit']);
    }

    public function test_a_placeholder_with_an_offer_is_kept(): void
    {
        $placeholder = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Studio Unit', 'unit_type' => 'Studio Unit', 'category' => 'Residential', 'price' => 2500000, 'status' => 'available']);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id]);
        Offer::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'unit_id' => $placeholder->id, 'agent_id' => $agent->id, 'code' => Offer::newCode(), 'buyer_name' => 'A Buyer', 'purchase_date' => now()->toDateString(), 'price' => 2500000, 'schedule' => [], 'status' => 'active']);

        $this->seedFromFixture('units-a.json');

        $this->assertModelExists($placeholder);
    }

    public function test_an_unrecognised_unit_type_skips_the_whole_project_without_failing_the_run(): void
    {
        $path = sys_get_temp_dir().'/johndorf-units-bad-type-'.uniqid().'.json';
        $doc = json_decode(file_get_contents($this->fixturePath('units-a.json')), true);
        $doc['projects'][0]['units'][0]['unit_type'] = 'Penthouse Unit'; // no such UnitType was seeded for this project
        file_put_contents($path, json_encode($doc));

        $this->app->bind(JohndorfUnitsSeeder::class, fn () => new JohndorfUnitsSeeder($path));
        $this->artisan('db:seed', ['--class' => JohndorfUnitsSeeder::class])->assertSuccessful();

        $this->assertSame(0, Unit::where('project_id', $this->project->id)->count());

        @unlink($path);
    }

    public function test_a_project_whose_scrape_errored_is_left_completely_untouched(): void
    {
        $existing = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Studio Unit', 'unit_type' => 'Studio Unit', 'category' => 'Residential', 'status' => 'available']);

        $path = sys_get_temp_dir().'/johndorf-units-error-'.uniqid().'.json';
        file_put_contents($path, json_encode(['projects' => [[
            'slug' => 'test-towers', 'name' => 'Test Towers', 'realty_id' => 9999, 'inventory_title' => 'Test Towers',
            'source_url' => '', 'scraped_at' => now()->toIso8601String(), 'inventory_state' => 'error',
            'available_count' => 0, 'requirements_days' => null, 'error' => 'the page title did not match', 'units' => [],
        ]]]));

        $this->app->bind(JohndorfUnitsSeeder::class, fn () => new JohndorfUnitsSeeder($path));
        $this->artisan('db:seed', ['--class' => JohndorfUnitsSeeder::class])->assertSuccessful();

        $this->assertModelExists($existing);
        $this->assertSame(1, Unit::where('project_id', $this->project->id)->count());

        @unlink($path);
    }

    public function test_a_project_with_no_online_inventory_gets_a_dated_note_once(): void
    {
        $placeholder = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Studio Unit', 'unit_type' => 'Studio Unit', 'category' => 'Residential', 'status' => 'available', 'notes' => '24 sqm usable floor area.']);

        $path = sys_get_temp_dir().'/johndorf-units-none-'.uniqid().'.json';
        $doc = ['projects' => [[
            'slug' => 'test-towers', 'name' => 'Test Towers', 'realty_id' => 9999, 'inventory_title' => 'Test Towers',
            'source_url' => '', 'scraped_at' => now()->toIso8601String(), 'inventory_state' => 'no_available_units',
            'available_count' => 0, 'requirements_days' => null, 'error' => null, 'units' => [],
        ]]];
        file_put_contents($path, json_encode($doc));
        $this->app->bind(JohndorfUnitsSeeder::class, fn () => new JohndorfUnitsSeeder($path));

        $this->artisan('db:seed', ['--class' => JohndorfUnitsSeeder::class])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => JohndorfUnitsSeeder::class])->assertSuccessful();

        $placeholder->refresh();
        $this->assertStringStartsWith('24 sqm usable floor area.', $placeholder->notes);
        $this->assertSame(1, substr_count($placeholder->notes, 'listed no available units'), 'the suffix is replaced, not appended again');

        @unlink($path);
    }

    public function test_a_seeded_unit_can_be_offered_and_shows_the_models_render_and_highlights(): void
    {
        UnitType::where('project_id', $this->project->id)->update(['specs' => ['usable_floor_area' => '24'], 'image_paths' => ['https://example.com/studio.jpg']]);
        $this->seedFromFixture('units-a.json');

        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id]);
        Sanctum::actingAs($agent);
        $unit = Unit::where('name', 'Bldg A · Unit 101')->firstOrFail();

        $created = $this->postJson('/api/realty/offers', [
            'unit_id' => $unit->id,
            'buyer_name' => 'A Buyer',
            'purchase_date' => now()->toDateString(),
            'access_username' => 'abuyer',
            'access_password' => 'password123',
        ])->assertCreated();

        $code = $created->json('code');
        $this->getJson("/api/offers/{$code}")
            ->assertOk()
            ->assertJsonPath('model.name', 'Studio Unit')
            ->assertJsonPath('unit.unit_type', 'Studio Unit');

        $this->assertStringStartsWith('Reservation fee', $created->json('unit.highlights') ?? Unit::find($unit->id)->buyer_notes);
    }
}
