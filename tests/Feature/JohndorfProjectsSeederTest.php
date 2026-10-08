<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use Database\Seeders\JohndorfProjectsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JohndorfProjectsSeeder against the real seed data: projects, house models,
 * then (through JohndorfUnitsSeeder) the real inventory, then each unit's
 * floor plan from its house model.
 */
class JohndorfProjectsSeederTest extends TestCase
{
    use RefreshDatabase;

    private const S3 = 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/jvconline/johndorf/projects/';

    private const STUDIO_PLAN = self::S3.'plumera/unit-1-1.jpg';

    private const ONE_BR_PLAN = self::S3.'plumera/unit-2-1.jpg';

    private const CARCAR_PLAN = '/johndorf/site/projects/tierranava-carcar/floor-plan.png';

    protected function setUp(): void
    {
        parent::setUp();

        Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
    }

    /** Units of this type in this project whose plan is anything but the expected one (null included). */
    private function offPlan(Project $project, string $type, string $plan): int
    {
        return Unit::where('project_id', $project->id)->where('unit_type', $type)
            ->where(fn ($q) => $q->whereNull('floor_plan_path')->orWhere('floor_plan_path', '!=', $plan))
            ->count();
    }

    public function test_every_unit_gets_its_models_floor_plan_and_a_photo(): void
    {
        $this->seed(JohndorfProjectsSeeder::class);

        $plumera = Project::where('slug', 'plumera')->firstOrFail();
        $this->assertGreaterThan(0, Unit::where('project_id', $plumera->id)->where('unit_type', 'Studio Unit')->count());
        $this->assertSame(0, $this->offPlan($plumera, 'Studio Unit', self::STUDIO_PLAN));
        $this->assertGreaterThan(0, Unit::where('project_id', $plumera->id)->where('unit_type', '1BR Unit')->count());
        $this->assertSame(0, $this->offPlan($plumera, '1BR Unit', self::ONE_BR_PLAN));

        $carcar = Project::where('slug', 'tierranava-carcar')->firstOrFail();
        $this->assertSame(2, Unit::where('project_id', $carcar->id)->where('floor_plan_path', self::CARCAR_PLAN)->count());

        // A model Johndorf publishes no plan for keeps none.
        $montierra = Project::where('slug', 'montierra')->firstOrFail();
        $townhouse = Unit::where('project_id', $montierra->id)->where('name', 'Two-Storey Townhouse')->firstOrFail();
        $this->assertNull($townhouse->floor_plan_path);

        // The picture is a photo, never the attached plan.
        $studio = Unit::where('project_id', $plumera->id)->where('unit_type', 'Studio Unit')->first();
        $this->assertSame('/johndorf/site/plumera.jpg', $studio->photo($plumera->unitTypes, $plumera));
        $this->assertSame(self::S3.'montierra/unit-1-1.jpg', $townhouse->photo($montierra->unitTypes, $montierra));
    }

    public function test_a_staff_uploaded_floor_plan_survives_a_re_seed_while_a_seeded_one_is_refreshed(): void
    {
        $this->seed(JohndorfProjectsSeeder::class);
        $plumera = Project::where('slug', 'plumera')->firstOrFail();
        [$uploaded, $stale] = Unit::where('project_id', $plumera->id)->where('unit_type', 'Studio Unit')->orderBy('id')->limit(2)->get();
        $uploaded->update(['floor_plan_path' => 'floor-plans/by-staff.jpg']);
        $stale->update(['floor_plan_path' => 'https://old.example/plan.jpg']);

        $this->seed(JohndorfProjectsSeeder::class);

        $this->assertSame('floor-plans/by-staff.jpg', $uploaded->fresh()->floor_plan_path);
        $this->assertSame(self::STUDIO_PLAN, $stale->fresh()->floor_plan_path);
    }
}
