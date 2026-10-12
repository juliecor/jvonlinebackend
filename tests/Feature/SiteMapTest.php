<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\SiteRow;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The clickable site plan: Johndorf's admins draw rows of lots on a project's plan, each lot a unit
 * with its block and lot number; everyone sees the lots and their status, the public without prices.
 */
class SiteMapTest extends TestCase
{
    use RefreshDatabase;

    private Realty $johndorf;

    private User $admin;

    private Project $project;

    /** @var array<int, array<int, float>> */
    private array $corners = [[0.1, 0.2], [0.5, 0.2], [0.5, 0.3], [0.1, 0.3]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->johndorf = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id]);
        $this->project = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'TierraNava Opol', 'slug' => 'tierranava-opol', 'status' => 'active', 'is_public' => true,
            'site_plan_paths' => ['https://cdn.example.com/opol/plan-1.jpg', 'https://cdn.example.com/opol/plan-2.jpg']]);
    }

    public function test_drawing_a_row_makes_a_unit_for_every_lot(): void
    {
        Sanctum::actingAs($this->admin);

        $map = $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", [
            'block' => '4', 'corners' => $this->corners, 'first_lot' => 18, 'lot_count' => 17,
            'unit_type' => 'Two-Storey Townhouse', 'lot_area_sqm' => 42, 'area_sqm' => 36, 'price' => 1900000,
        ])->assertCreated()->json();

        $this->assertSame('https://cdn.example.com/opol/plan-1.jpg', $map['image']);
        $this->assertCount(1, $map['rows']);
        $this->assertCount(17, $map['lots']);
        $this->assertSame(['4', '18', 0], [$map['lots'][0]['block'], $map['lots'][0]['lot'], $map['lots'][0]['index']]);
        $this->assertSame('34', $map['lots'][16]['lot']);
        $unit = Unit::where('lot', '18')->firstOrFail();
        $this->assertSame(['Block 4 · Lot 18', 'Two-Storey Townhouse', '42.00', '36.00', '1900000.00', 'available', 'Residential'], [$unit->name, $unit->unit_type, $unit->lot_area_sqm, $unit->area_sqm, $unit->price, $unit->status, $unit->category]);
        $this->getJson("/api/realty/projects/{$this->project->id}")->assertJsonCount(17, 'site_map.lots')->assertJsonPath('site_map.lots.0.price', '1900000.00');
    }

    public function test_a_lot_that_already_exists_by_name_is_linked_not_copied(): void
    {
        $existing = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $this->project->id, 'name' => 'Block 24 · Lot 27', 'unit_type' => 'Old model', 'price' => 2300000, 'status' => 'reserved']);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '24', 'corners' => $this->corners, 'first_lot' => 26, 'lot_count' => 3, 'unit_type' => 'New model', 'lot_area_sqm' => 42])->assertCreated();

        $this->assertSame(3, Unit::where('block', '24')->count());
        $existing->refresh();
        // Its own model, price and status stay; only what it lacked is filled in.
        $this->assertSame(['24', '27', 1, 'Old model', '2300000.00', 'reserved', '42.00'], [$existing->block, $existing->lot, $existing->row_index, $existing->unit_type, $existing->price, $existing->status, $existing->lot_area_sqm]);
    }

    public function test_lots_already_on_the_map_cannot_be_drawn_twice(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '4', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 10])->assertCreated();

        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '4', 'corners' => $this->corners, 'first_lot' => 9, 'lot_count' => 5])
            ->assertJsonValidationErrors(['first_lot' => 'Block 4 lots 9, 10 are already on the map.']);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '5', 'corners' => $this->corners, 'first_lot' => 9, 'lot_count' => 5])->assertCreated();
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '6', 'corners' => [[0, 0], [2, 0], [1, 1], [0, 1]], 'first_lot' => 1, 'lot_count' => 2])->assertJsonValidationErrors('corners.1.0');
    }

    public function test_a_row_can_be_moved_given_values_and_taken_off_keeping_lots_with_offers(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '1', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 4])->assertCreated();
        $row = SiteRow::firstOrFail();

        $moved = [[0.2, 0.2], [0.6, 0.2], [0.6, 0.3], [0.2, 0.3]];
        $this->postJson("/api/realty/site-rows/{$row->id}", ['corners' => $moved])->assertOk()->assertJsonPath('rows.0.corners.0.0', 0.2);
        $this->postJson("/api/realty/site-rows/{$row->id}/apply", ['price' => 2100000, 'unit_type' => 'Townhouse'])->assertOk();
        $this->assertSame(4, Unit::where('price', 2100000)->where('unit_type', 'Townhouse')->count());
        $this->postJson("/api/realty/site-rows/{$row->id}/apply", [])->assertStatus(422);

        $sold = Unit::where('lot', '2')->firstOrFail();
        Offer::create(['realty_id' => $this->johndorf->id, 'project_id' => $this->project->id, 'unit_id' => $sold->id, 'agent_id' => $this->admin->id, 'code' => Offer::newCode(), 'buyer_name' => 'Rosa', 'purchase_date' => now()->toDateString(), 'price' => 2100000, 'schedule' => [], 'status' => 'active']);

        $this->deleteJson("/api/realty/site-rows/{$row->id}")->assertOk()->assertJsonPath('kept', 1)->assertJsonCount(0, 'site_map.lots');
        $this->assertSame(['Block 1 · Lot 2'], Unit::pluck('name')->all());
        $this->assertNull($sold->fresh()->site_row_id);
    }

    public function test_a_lot_is_edited_with_its_area_and_tags(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '1', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 2])->assertCreated();
        $unit = Unit::where('lot', '1')->firstOrFail();

        $this->postJson("/api/realty/units/{$unit->id}", ['name' => $unit->name, 'lot_area_sqm' => 71, 'tags' => ['corner', 'park'], 'price' => 2500000])->assertOk();
        $this->assertSame(['71.00', ['corner', 'park']], [$unit->fresh()->lot_area_sqm, $unit->fresh()->tags]);
        $this->postJson("/api/realty/units/{$unit->id}", ['name' => $unit->name, 'tags' => ['pool']])->assertJsonValidationErrors('tags.0');
    }

    public function test_the_plan_image_is_one_of_the_projects_site_plans(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/image", ['image' => 'https://cdn.example.com/opol/plan-2.jpg'])->assertOk()->assertJsonPath('image', 'https://cdn.example.com/opol/plan-2.jpg');
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/image", ['image' => 'https://evil.example.com/x.jpg'])->assertStatus(422);

        $bare = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'No plan yet', 'status' => 'active']);
        $this->postJson("/api/realty/projects/{$bare->id}/site-map/rows", ['block' => '1', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 2])->assertStatus(422);
    }

    public function test_the_website_and_the_buyer_see_lots_and_status_but_no_prices(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/realties/johndorf/projects/tierranava-opol')->assertOk()->assertJsonPath('site_map', null);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '1', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 3, 'price' => 1900000, 'lot_area_sqm' => 42])->assertCreated();
        Unit::where('lot', '2')->update(['status' => 'sold']);

        $public = $this->getJson('/api/realties/johndorf/projects/tierranava-opol')->assertOk()->json('site_map');
        $this->assertSame(['available', 'sold', 'available'], array_column($public['lots'], 'status'));
        $this->assertSame([null, null, null], array_column($public['lots'], 'price'));

        $unit = Unit::where('lot', '3')->firstOrFail();
        $offer = Offer::create(['realty_id' => $this->johndorf->id, 'project_id' => $this->project->id, 'unit_id' => $unit->id, 'agent_id' => $this->admin->id, 'code' => Offer::newCode(), 'buyer_name' => 'Rosa', 'purchase_date' => now()->toDateString(), 'price' => 1900000, 'schedule' => [], 'status' => 'active']);
        $buyer = $offer->publicArray();
        $this->assertSame($unit->id, $buyer['site_map']['highlight']);
        $this->assertSame(['1', '3', 42.0], [$buyer['unit']['block'], $buyer['unit']['lot'], $buyer['unit']['lot_area_sqm']]);
        $this->assertNull($buyer['site_map']['lots'][0]['price']);
    }

    public function test_lots_get_photos_one_by_one_or_a_whole_row_at_once(): void
    {
        $disk = Storage::fake(config('filesystems.uploads'));
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '1', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 3])->assertCreated();
        $row = SiteRow::firstOrFail();
        [$one, $two] = Unit::orderBy('row_index')->take(2)->get();

        // A row's photo is stored once and shared by its lots.
        $this->post("/api/realty/site-rows/{$row->id}/photos", ['photos' => [UploadedFile::fake()->image('model.jpg')]], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('skipped', 0)->assertJsonCount(1, 'site_map.lots.0.photos');
        $shared = $one->fresh()->photo_paths[0];
        $this->assertSame($shared, $two->fresh()->photo_paths[0]);
        $this->assertCount(1, $disk->allFiles());

        // A lot's own photo comes after; it shows on the lot first only by order of upload.
        $photos = $this->post("/api/realty/units/{$one->id}/photos", ['photos' => [UploadedFile::fake()->image('house.jpg')]], ['Accept' => 'application/json'])->assertOk()->json('photos');
        $this->assertCount(2, $photos);
        $this->post("/api/realty/units/{$one->id}/photos", ['photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertJsonValidationErrors('photos.0');

        // Removing the shared photo from one lot keeps the file for the others; from the last lot, the file goes.
        $sharedUrl = $photos[0];
        $this->postJson("/api/realty/units/{$one->id}/photos/remove", ['url' => $sharedUrl])->assertOk()->assertJsonCount(1, 'photos');
        $disk->assertExists($shared);
        foreach (Unit::where('id', '!=', $one->id)->get() as $u) {
            $this->postJson("/api/realty/units/{$u->id}/photos/remove", ['url' => $sharedUrl])->assertOk();
        }
        $disk->assertMissing($shared);

        // Taking the row off the map deletes its lots' photos.
        $this->deleteJson("/api/realty/site-rows/{$row->id}")->assertOk();
        $this->assertSame([], $disk->allFiles());
    }

    public function test_the_buyer_and_the_website_see_the_lots_photos(): void
    {
        Storage::fake(config('filesystems.uploads'));
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", ['block' => '1', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 1])->assertCreated();
        $unit = Unit::firstOrFail();
        $this->post("/api/realty/units/{$unit->id}/photos", ['photos' => [UploadedFile::fake()->image('house.jpg')]], ['Accept' => 'application/json'])->assertOk();

        $this->getJson('/api/realties/johndorf/projects/tierranava-opol')->assertJsonCount(1, 'site_map.lots.0.photos');
        $offer = Offer::create(['realty_id' => $this->johndorf->id, 'project_id' => $this->project->id, 'unit_id' => $unit->id, 'agent_id' => $this->admin->id, 'code' => Offer::newCode(), 'buyer_name' => 'Rosa', 'purchase_date' => now()->toDateString(), 'price' => 1, 'schedule' => [], 'status' => 'active']);
        $this->assertCount(1, $offer->publicArray()['unit']['photos']);
    }

    public function test_only_johndorfs_admins_with_projects_draw_on_the_map(): void
    {
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->johndorf->id]);
        $broker = Realty::create(['name' => 'ABC Realty', 'slug' => 'abc-realty', 'kind' => Realty::KIND_BROKER, 'developer_id' => $this->johndorf->id, 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $brokerAdmin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $broker->id]);
        $offersOnly = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id, 'permissions' => ['offers']]);
        $body = ['block' => '1', 'corners' => $this->corners, 'first_lot' => 1, 'lot_count' => 2];

        foreach ([$agent, $brokerAdmin, $offersOnly] as $who) {
            Sanctum::actingAs($who);
            $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", $body)->assertForbidden();
        }
        // But they all read it (the agent picks a lot for an offer).
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/projects/{$this->project->id}/site-map/rows", $body)->assertCreated();
        Sanctum::actingAs($agent);
        $this->getJson("/api/realty/projects/{$this->project->id}")->assertJsonCount(2, 'site_map.lots');
    }
}
