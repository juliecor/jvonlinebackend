<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\RequirementType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every realty has a list of what buyers send, so every offer shows a Requirements tab. */
class BuyerRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_realties_without_requirements_get_the_standard_list_and_others_keep_theirs(): void
    {
        $johndorf = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $other = Realty::create(['name' => 'Other Realty', 'slug' => 'other', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        RequirementType::create(['realty_id' => $other->id, 'name' => 'Their own list', 'applies' => 'all', 'sort' => 10]);

        RequirementType::seedMissing();
        RequirementType::seedMissing();

        $this->assertSame(['Valid government ID', 'Proof of income'], RequirementType::where('realty_id', $johndorf->id)->orderBy('sort')->limit(2)->pluck('name')->all());
        $this->assertSame(7, RequirementType::where('realty_id', $johndorf->id)->count());
        $this->assertSame(['Their own list'], RequirementType::where('realty_id', $other->id)->pluck('name')->all());

        // An offer sent before the list existed shows it to the buyer now.
        $project = Project::create(['realty_id' => $johndorf->id, 'name' => 'Plumera Mactan', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $johndorf->id, 'project_id' => $project->id, 'name' => 'Unit 123', 'price' => 3543000, 'status' => 'available']);
        $offer = Offer::create([
            'realty_id' => $johndorf->id, 'project_id' => $project->id, 'unit_id' => $unit->id,
            'agent_id' => User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $johndorf->id])->id,
            'code' => Offer::newCode(), 'buyer_name' => 'Anthony Leuterio', 'purchase_date' => now()->toDateString(),
            'price' => 3543000, 'schedule' => [], 'status' => 'active',
        ]);
        $this->assertContains('Valid government ID', array_column($this->getJson("/api/offers/{$offer->code}")->assertOk()->json('requirements'), 'name'));
    }
}
