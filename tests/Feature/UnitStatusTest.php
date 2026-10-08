<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A realty admin marks an offer's unit reserved or sold, and the project shows who has it. */
class UnitStatusTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $admin;

    private User $agent;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'name' => 'Johndorf Admin']);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ana Agent']);
        $project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Montierra', 'status' => 'active']);
        $this->unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'name' => 'Lot 12', 'category' => 'Residential', 'price' => 2800000, 'status' => 'available']);
    }

    public function test_the_admin_marks_the_unit_sold_from_the_offer_and_the_project_shows_who(): void
    {
        $offer = $this->offer('Juliecor Repompo');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/offers/{$offer->id}/unit-status", ['status' => 'reserved'])
            ->assertOk()
            ->assertJsonPath('status', 'reserved')
            ->assertJsonPath('this_offer', true)
            ->assertJsonPath('detail.buyer', 'Juliecor Repompo')
            ->assertJsonPath('detail.by', 'Johndorf Admin');
        $this->postJson("/api/realty/offers/{$offer->id}/unit-status", ['status' => 'sold'])->assertOk();

        $this->getJson("/api/realty/projects/{$this->unit->project_id}")
            ->assertJsonPath('units.0.status', 'sold')
            ->assertJsonPath('units.0.status_detail.buyer', 'Juliecor Repompo')
            ->assertJsonPath('units.0.status_detail.agent', 'Ana Agent')
            ->assertJsonPath('units.0.status_detail.offer_code', $offer->code);
        $this->getJson('/api/realty/offers')->assertJsonPath('0.unit_status', 'sold');
    }

    public function test_agents_see_the_status_but_cannot_change_it_or_see_other_buyers(): void
    {
        $offer = $this->offer('Juliecor Repompo');
        $this->unit->update(['status' => 'reserved', 'status_offer_id' => $offer->id, 'status_by_id' => $this->admin->id, 'status_at' => now()]);
        Sanctum::actingAs($this->agent);

        $this->postJson("/api/realty/offers/{$offer->id}/unit-status", ['status' => 'sold'])->assertForbidden();
        $this->getJson("/api/realty/projects/{$this->unit->project_id}")
            ->assertJsonPath('units.0.status', 'reserved')
            ->assertJsonPath('units.0.status_detail.agent', 'Ana Agent')
            ->assertJsonPath('units.0.status_detail.buyer', null);
    }

    public function test_a_unit_held_by_one_offer_cannot_be_taken_by_another_until_it_is_let_go(): void
    {
        $first = $this->offer('Juliecor Repompo');
        $second = $this->offer('Maria Buyer');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/offers/{$first->id}/unit-status", ['status' => 'reserved'])->assertOk();
        $this->postJson("/api/realty/offers/{$second->id}/unit-status", ['status' => 'sold'])
            ->assertStatus(409)
            ->assertJsonPath('message', "This unit is already reserved to Juliecor Repompo (offer {$first->code}). Set it back to available on that offer first.");

        $this->postJson("/api/realty/offers/{$first->id}/unit-status", ['status' => 'available'])->assertOk()->assertJsonPath('detail', null);
        $this->assertNull($this->unit->fresh()->status_offer_id);
        $this->postJson("/api/realty/offers/{$second->id}/unit-status", ['status' => 'sold'])->assertOk()->assertJsonPath('detail.buyer', 'Maria Buyer');
    }

    public function test_setting_the_unit_available_by_hand_lets_go_of_the_offer(): void
    {
        $offer = $this->offer('Juliecor Repompo');
        $this->unit->update(['status' => 'sold', 'status_offer_id' => $offer->id]);
        Sanctum::actingAs($this->admin);

        $this->post("/api/realty/units/{$this->unit->id}", ['name' => 'Lot 12', 'category' => 'Residential', 'price' => 2800000, 'status' => 'available'], ['Accept' => 'application/json'])->assertOk();

        $unit = $this->unit->fresh();
        $this->assertSame('available', $unit->status);
        $this->assertNull($unit->status_offer_id);
        $this->assertSame($this->admin->id, $unit->status_by_id);
    }

    private function offer(string $buyer): Offer
    {
        return Offer::create([
            'realty_id' => $this->realty->id,
            'project_id' => $this->unit->project_id,
            'unit_id' => $this->unit->id,
            'agent_id' => $this->agent->id,
            'code' => Offer::newCode(),
            'buyer_name' => $buyer,
            'purchase_date' => now()->toDateString(),
            'price' => 2800000,
            'schedule' => [],
            'status' => 'active',
        ]);
    }
}
