<?php

namespace Tests\Feature;

use App\Models\AccreditationDocument;
use App\Models\AgentInvitation;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\Project;
use App\Models\Realty;
use App\Models\RealtyAccreditation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Delete buttons on Projects, Offers, Agents and Realties: who may, and what goes with it. */
class DeleteActionsTest extends TestCase
{
    use RefreshDatabase;

    private Realty $johndorf;

    private Realty $abc;

    private User $admin;

    private User $agent;

    private Project $project;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->johndorf = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->abc = Realty::create(['name' => 'ABC Realty', 'slug' => 'abc-realty', 'kind' => Realty::KIND_BROKER, 'developer_id' => $this->johndorf->id, 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id]);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->johndorf->id]);
        $this->project = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'Montierra', 'status' => 'active']);
        $this->unit = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $this->project->id, 'name' => 'Lot 1', 'category' => 'Residential', 'price' => 2800000, 'status' => 'available']);
    }

    // ----- offers -----

    public function test_staff_delete_an_offer_and_its_unit_goes_back_to_available(): void
    {
        Storage::fake(OfferDocument::disk());
        $offer = $this->offer();
        $this->unit->update(['status' => 'reserved', 'status_offer_id' => $offer->id]);
        Storage::disk(OfferDocument::disk())->put('docs/id.pdf', 'x');
        OfferDocument::create(['offer_id' => $offer->id, 'realty_id' => $this->johndorf->id, 'path' => 'docs/id.pdf', 'original_name' => 'id.pdf', 'mime' => 'application/pdf', 'size' => 1, 'status' => 'pending']);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/offers/{$offer->id}")->assertOk()->assertJsonPath('deleted', $offer->id);

        $this->assertModelMissing($offer);
        $this->assertDatabaseCount('offer_documents', 0);
        Storage::disk(OfferDocument::disk())->assertMissing('docs/id.pdf');
        $this->assertSame('available', $this->unit->fresh()->status);
        $this->assertNull($this->unit->fresh()->status_offer_id);
    }

    public function test_an_offer_keeps_a_unit_that_another_offer_holds(): void
    {
        $mine = $this->offer();
        $other = $this->offer();
        $this->unit->update(['status' => 'sold', 'status_offer_id' => $other->id]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/offers/{$mine->id}")->assertOk();

        $this->assertSame('sold', $this->unit->fresh()->status);
        $this->assertSame($other->id, $this->unit->fresh()->status_offer_id);
    }

    public function test_agents_cannot_delete_offers_and_staff_cannot_reach_another_realtys(): void
    {
        $offer = $this->offer();

        Sanctum::actingAs($this->agent);
        $this->deleteJson("/api/realty/offers/{$offer->id}")->assertForbidden();

        $abcAdmin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->abc->id]);
        Sanctum::actingAs($abcAdmin);
        $this->deleteJson("/api/realty/offers/{$offer->id}")->assertNotFound();
        $this->assertModelExists($offer);
    }

    // ----- projects -----

    public function test_a_project_without_offers_is_deleted_with_its_units_and_pictures(): void
    {
        Storage::fake(config('filesystems.uploads'));
        Storage::disk(config('filesystems.uploads'))->put('projects/cover.jpg', 'x');
        $this->project->update(['cover_path' => 'projects/cover.jpg']);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/projects/{$this->project->id}")->assertOk();

        $this->assertModelMissing($this->project);
        $this->assertModelMissing($this->unit);
        Storage::disk(config('filesystems.uploads'))->assertMissing('projects/cover.jpg');
    }

    public function test_a_project_with_offers_is_not_deleted(): void
    {
        $this->offer();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/projects/{$this->project->id}")->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Archive it instead'));

        $this->assertModelExists($this->project);
    }

    public function test_agents_and_other_realties_cannot_delete_a_project(): void
    {
        Sanctum::actingAs($this->agent);
        $this->deleteJson("/api/realty/projects/{$this->project->id}")->assertForbidden();

        $abcAdmin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->abc->id]);
        Sanctum::actingAs($abcAdmin);
        $this->deleteJson("/api/realty/projects/{$this->project->id}")->assertForbidden();
        $this->assertModelExists($this->project);
    }

    // ----- agents -----

    public function test_staff_delete_an_active_agent_whose_offers_stay(): void
    {
        $offer = $this->offer();
        $this->agent->createToken('web');

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/agents/{$this->agent->id}")->assertNoContent();

        $this->assertModelMissing($this->agent);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($offer->fresh()->agent_id);
    }

    public function test_staff_cannot_delete_a_colleague_or_another_realtys_agent(): void
    {
        $abcAgent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->abc->id]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/agents/{$this->admin->id}")->assertNotFound();
        $this->deleteJson("/api/realty/agents/{$abcAgent->id}")->assertNotFound();
        $this->assertModelExists($this->admin);
        $this->assertModelExists($abcAgent);
    }

    public function test_staff_take_back_an_open_invitation(): void
    {
        [$invitation] = AgentInvitation::issue($this->johndorf, 'Ana Cruz', 'ana@example.com', $this->admin);
        [$other] = AgentInvitation::issue($this->abc, 'Ben Cruz', null, null);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/agents/invitations/{$other->id}")->assertNotFound();
        $this->deleteJson("/api/realty/agents/invitations/{$invitation->id}")->assertNoContent();

        $this->assertModelMissing($invitation);
        $this->assertModelExists($other);
    }

    // ----- realties -----

    public function test_a_rejected_form_and_an_open_invite_can_be_deleted_but_a_form_in_review_cannot(): void
    {
        Storage::fake(AccreditationDocument::disk());
        [$invite] = RealtyAccreditation::issue($this->johndorf, 'new@firm.test', $this->admin);
        [$rejected] = RealtyAccreditation::issue($this->johndorf, 'no@firm.test', $this->admin);
        $rejected->update(['status' => RealtyAccreditation::STATUS_REJECTED]);
        Storage::disk(AccreditationDocument::disk())->put('forms/prc.pdf', 'x');
        AccreditationDocument::create(['accreditation_id' => $rejected->id, 'kind' => 'prc', 'path' => 'forms/prc.pdf', 'original_name' => 'prc.pdf', 'mime' => 'application/pdf', 'size' => 1]);
        [$review] = RealtyAccreditation::issue($this->johndorf, 'wait@firm.test', $this->admin);
        $review->update(['status' => RealtyAccreditation::STATUS_SUBMITTED]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/realties/accreditations/{$invite->id}")->assertOk();
        $this->deleteJson("/api/realty/realties/accreditations/{$rejected->id}")->assertOk();
        $this->deleteJson("/api/realty/realties/accreditations/{$review->id}")->assertStatus(409);

        $this->assertModelMissing($invite);
        $this->assertModelMissing($rejected);
        $this->assertModelExists($review);
        Storage::disk(AccreditationDocument::disk())->assertMissing('forms/prc.pdf');
    }

    public function test_an_accredited_broker_is_deleted_with_its_people_and_form(): void
    {
        [$form] = RealtyAccreditation::issue($this->johndorf, 'abc@firm.test', $this->admin);
        $form->update(['status' => RealtyAccreditation::STATUS_APPROVED, 'realty_id' => $this->abc->id]);
        $abcAdmin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->abc->id]);
        $abcAgent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->abc->id]);
        $abcAdmin->createToken('web');

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/realties/{$this->abc->id}")->assertOk();

        $this->assertModelMissing($this->abc);
        $this->assertModelMissing($abcAdmin);
        $this->assertModelMissing($abcAgent);
        $this->assertModelMissing($form);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertModelExists($this->johndorf);
        $this->assertModelExists($this->admin);
    }

    public function test_a_broker_that_sold_offers_is_not_deleted(): void
    {
        $this->offer(['broker_realty_id' => $this->abc->id]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/realties/{$this->abc->id}")->assertStatus(409);

        $this->assertModelExists($this->abc);
    }

    public function test_only_the_developers_admins_delete_realties_and_never_the_developer_itself(): void
    {
        Sanctum::actingAs($this->agent);
        $this->deleteJson("/api/realty/realties/{$this->abc->id}")->assertForbidden();

        $abcAdmin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->abc->id]);
        Sanctum::actingAs($abcAdmin);
        $this->deleteJson("/api/realty/realties/{$this->abc->id}")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/realty/realties/{$this->johndorf->id}")->assertNotFound();
        $this->assertModelExists($this->abc);
        $this->assertModelExists($this->johndorf);
    }

    /** @param  array<string, mixed>  $overrides */
    private function offer(array $overrides = []): Offer
    {
        return Offer::create($overrides + [
            'realty_id' => $this->johndorf->id,
            'project_id' => $this->project->id,
            'unit_id' => $this->unit->id,
            'agent_id' => $this->agent->id,
            'code' => Offer::newCode(),
            'buyer_name' => 'Juliecor Repompo',
            'purchase_date' => now()->toDateString(),
            'price' => 2800000,
            'schedule' => [],
            'status' => 'active',
        ]);
    }
}
