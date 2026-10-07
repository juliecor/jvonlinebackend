<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The status bar on a project (open or archived, stage, on the website) and the counts the project list filters on. */
class ProjectStatusTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $staff;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->staff = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id]);
        $this->project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Montierra', 'slug' => 'montierra', 'stage' => 'Ongoing', 'status' => 'active', 'is_public' => true]);
    }

    public function test_staff_change_one_status_field_without_touching_the_others(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/projects/{$this->project->id}/status", ['stage' => 'Sold out'])
            ->assertOk()
            ->assertJsonPath('stage', 'Sold out')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_public', true);

        $this->postJson("/api/realty/projects/{$this->project->id}/status", ['status' => 'archived', 'is_public' => false])->assertOk();

        $this->project->refresh();
        $this->assertSame('Sold out', $this->project->stage);
        $this->assertSame('archived', $this->project->status);
        $this->assertFalse($this->project->is_public);
    }

    public function test_stage_can_be_cleared_but_must_be_one_of_the_list(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/projects/{$this->project->id}/status", ['stage' => 'Almost done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');

        $this->postJson("/api/realty/projects/{$this->project->id}/status", ['stage' => null])->assertOk();
        $this->assertNull($this->project->fresh()->stage);
    }

    public function test_publishing_a_project_without_a_page_address_gives_it_one(): void
    {
        Sanctum::actingAs($this->staff);
        Project::create(['realty_id' => $this->realty->id, 'name' => 'Navona Court', 'slug' => 'navona-court']);
        $draft = Project::create(['realty_id' => $this->realty->id, 'name' => 'Navona Court']);

        $this->postJson("/api/realty/projects/{$draft->id}/status", ['is_public' => true])
            ->assertOk()
            ->assertJsonPath('slug', "navona-court-{$draft->id}");
    }

    public function test_agents_and_other_realties_cannot_change_the_status(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id]));
        $this->postJson("/api/realty/projects/{$this->project->id}/status", ['status' => 'archived'])->assertForbidden();

        $other = Realty::create(['name' => 'Other Realty', 'slug' => 'other', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $other->id]));
        $this->postJson("/api/realty/projects/{$this->project->id}/status", ['status' => 'archived'])->assertNotFound();

        $this->assertSame('active', $this->project->fresh()->status);
    }

    public function test_the_project_list_counts_units_ready_to_offer(): void
    {
        Sanctum::actingAs($this->staff);
        $unit = ['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'category' => 'Residential'];
        Unit::create($unit + ['name' => 'Lot 1', 'price' => 2500000, 'status' => 'available']);
        Unit::create($unit + ['name' => 'Lot 2', 'price' => null, 'status' => 'available']);
        Unit::create($unit + ['name' => 'Lot 3', 'price' => 2600000, 'status' => 'sold']);

        $this->getJson('/api/realty/projects')
            ->assertOk()
            ->assertJsonPath('0.units_count', 3)
            ->assertJsonPath('0.ready_units_count', 1);
    }
}
