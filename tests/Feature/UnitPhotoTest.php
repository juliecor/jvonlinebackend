<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Every unit row and offer row gets a picture: the house model's photo, else the project's, else the unit's floor plan. */
class UnitPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const RENDER = 'https://img.example/studio-render.jpg';

    private const PLAN = 'https://img.example/studio-plan.jpg';

    private const HERO = '/johndorf/site/hero.jpg';

    private Realty $realty;

    private User $staff;

    private Project $project;

    private Unit $withModel;

    private Unit $noModel;

    private Unit $planOnlyModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->staff = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id]);
        $this->project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Plumera Mactan', 'status' => 'active', 'cover_path' => '/johndorf/site/cover.jpg', 'hero_paths' => [self::HERO]]);
        UnitType::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Studio Unit', 'image_paths' => [self::RENDER, self::PLAN], 'sort' => 0]);
        UnitType::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Plan Only', 'image_paths' => ['https://img.example/plan-only.jpg'], 'sort' => 1]);

        $base = ['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'category' => 'Residential', 'status' => 'available', 'price' => 3000000];
        $this->withModel = Unit::create($base + ['name' => 'Bldg A · Unit 101', 'unit_type' => 'Studio Unit', 'floor_plan_path' => self::PLAN]);
        $this->noModel = Unit::create($base + ['name' => 'Lot 99', 'unit_type' => null]);
        $this->planOnlyModel = Unit::create($base + ['name' => 'Unit C', 'unit_type' => 'Plan Only', 'floor_plan_path' => 'https://img.example/plan-only.jpg']);
    }

    public function test_the_project_page_gives_each_unit_a_picture_without_embedding_the_project(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->getJson("/api/realty/projects/{$this->project->id}")->assertOk();
        $units = collect($response->json('units'))->keyBy('name');

        $this->assertSame(self::RENDER, $units['Bldg A · Unit 101']['photo'], 'the model render, not the attached plan');
        $this->assertSame(self::HERO, $units['Lot 99']['photo'], 'no model: the project hero');
        $this->assertSame(self::HERO, $units['Unit C']['photo'], "a model whose only image is the unit's own plan: the project hero");
        $response->assertJsonMissingPath('units.0.project');
    }

    public function test_the_offers_list_shows_the_same_picture(): void
    {
        Sanctum::actingAs($this->staff);
        Offer::create([
            'realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'unit_id' => $this->withModel->id, 'agent_id' => $this->staff->id,
            'code' => Offer::newCode(), 'buyer_name' => 'A Buyer', 'purchase_date' => now()->toDateString(), 'price' => 3000000, 'schedule' => [], 'status' => 'active',
        ]);

        $rows = $this->getJson('/api/realty/offers')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame(self::RENDER, $rows[0]['photo']);
    }

    public function test_with_no_model_hero_or_cover_the_floor_plan_is_the_picture(): void
    {
        $bare = Project::create(['realty_id' => $this->realty->id, 'name' => 'Bare', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $bare->id, 'name' => 'Lot 1', 'category' => 'Residential', 'status' => 'available', 'floor_plan_path' => 'https://img.example/alone.jpg']);
        $nothing = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $bare->id, 'name' => 'Lot 2', 'category' => 'Residential', 'status' => 'available']);

        $this->assertSame('https://img.example/alone.jpg', $unit->photo());
        $this->assertNull($nothing->photo());
    }
}
