<?php

namespace Tests\Feature;

use App\Mail\ApprovalRequestMail;
use App\Mail\ApprovalResultMail;
use App\Mail\OfferResponseMail;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Johndorf is the developer; accredited realties (brokers) sell its units through their own
 * agents. A broker reads Johndorf's inventory and makes offers on it, but never edits it,
 * approves terms, checks a buyer's files or sees another firm's sales.
 */
class BrokerAccessTest extends TestCase
{
    use RefreshDatabase;

    private Realty $johndorf;

    private Realty $abc;

    private Realty $xyz;

    private User $admin;

    private User $johndorfAgent;

    private User $abcAdmin;

    private User $abcAgent;

    private User $abcOtherAgent;

    private User $xyzAgent;

    private Project $project;

    private Unit $unit;

    private Unit $otherUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->johndorf = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(), 'accent_color' => '#b4241c']);
        $this->abc = $this->broker('ABC Realty', 'abc-realty');
        $this->xyz = $this->broker('XYZ Realty', 'xyz-realty');

        $this->admin = $this->person($this->johndorf, User::ROLE_REALTY, 'Johndorf Admin');
        $this->johndorfAgent = $this->person($this->johndorf, User::ROLE_AGENT, 'Jo Agent');
        $this->abcAdmin = $this->person($this->abc, User::ROLE_REALTY, 'Abby Admin');
        $this->abcAgent = $this->person($this->abc, User::ROLE_AGENT, 'Ana Cruz');
        $this->abcOtherAgent = $this->person($this->abc, User::ROLE_AGENT, 'Ben Cruz');
        $this->xyzAgent = $this->person($this->xyz, User::ROLE_AGENT, 'Xavi Agent');

        $this->project = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'Montierra', 'status' => 'active']);
        $this->unit = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $this->project->id, 'name' => 'Lot 1', 'category' => 'Residential', 'price' => 2800000, 'status' => 'available', 'notes' => 'Johndorf inventory #9 · internal financing notes']);
        $this->otherUnit = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $this->project->id, 'name' => 'Lot 2', 'category' => 'Residential', 'price' => 2900000, 'status' => 'available']);
    }

    // ----- what a broker sees and can sell -----

    public function test_a_broker_reads_the_developers_projects_without_its_internal_notes(): void
    {
        Sanctum::actingAs($this->abcAgent);

        $this->getJson('/api/realty/projects')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Montierra');
        $this->getJson("/api/realty/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonCount(2, 'units')
            ->assertJsonMissingPath('units.0.notes');

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/realty/projects/{$this->project->id}")->assertJsonPath('units.0.notes', 'Johndorf inventory #9 · internal financing notes');
    }

    public function test_a_broker_agent_makes_an_offer_on_a_johndorf_unit_owned_by_johndorf_and_sold_by_the_broker(): void
    {
        Sanctum::actingAs($this->abcAgent);

        $id = $this->postJson('/api/realty/offers', $this->form())->assertCreated()->json('id');

        $offer = Offer::findOrFail($id);
        $this->assertSame($this->johndorf->id, $offer->realty_id);
        $this->assertSame($this->abc->id, $offer->broker_realty_id);
        $this->assertSame($this->abcAgent->id, $offer->agent_id);
        $this->assertSame('ABC Realty', $offer->sellerName());
    }

    public function test_a_johndorf_offer_has_no_broker(): void
    {
        Sanctum::actingAs($this->johndorfAgent);

        $id = $this->postJson('/api/realty/offers', $this->form())->assertCreated()->json('id');

        $this->assertNull(Offer::findOrFail($id)->broker_realty_id);
        $this->assertSame('Johndorf Ventures Corporation', Offer::findOrFail($id)->sellerName());
    }

    public function test_only_open_units_of_active_projects_can_be_offered(): void
    {
        Sanctum::actingAs($this->abcAgent);

        $this->unit->update(['status' => 'reserved']);
        $this->postJson('/api/realty/offers', $this->form())->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);

        $this->unit->update(['status' => 'available']);
        $this->project->update(['status' => 'archived']);
        $this->postJson('/api/realty/offers', $this->form())->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);
    }

    public function test_a_broker_cannot_offer_a_unit_of_another_realty(): void
    {
        $other = Realty::create(['name' => 'Someone Else Dev', 'slug' => 'someone-else', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $project = Project::create(['realty_id' => $other->id, 'name' => 'Elsewhere', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $other->id, 'project_id' => $project->id, 'name' => 'Lot 9', 'category' => 'Residential', 'price' => 1000000, 'status' => 'available']);
        Sanctum::actingAs($this->abcAgent);

        $this->postJson('/api/realty/offers', $this->form(['unit_id' => $unit->id]))->assertNotFound();
        $this->getJson("/api/realty/projects/{$project->id}")->assertNotFound();
    }

    // ----- custom terms go to Johndorf -----

    public function test_a_brokers_custom_terms_wait_for_johndorfs_admins_and_nobody_else(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->abcAgent);

        $id = $this->postJson('/api/realty/offers', $this->form(['custom' => true, 'custom_milestones' => $this->terms(), 'approval_reason' => 'Buyer needs 24 months']))
            ->assertCreated()
            ->assertJsonPath('approval_status', 'pending')
            ->json('id');

        Mail::assertSent(ApprovalRequestMail::class, fn (ApprovalRequestMail $m) => $m->hasTo($this->admin->email) && str_contains($m->dashboardUrl, "/johndorf/dashboard/offers/{$id}"));
        Mail::assertNotSent(ApprovalRequestMail::class, fn (ApprovalRequestMail $m) => $m->hasTo($this->abcAdmin->email));
    }

    public function test_a_brokers_admin_cannot_approve_even_its_own_terms(): void
    {
        $offer = $this->offer($this->abcAdmin, ['approval_status' => 'pending', 'custom_milestones' => $this->terms()]);
        Sanctum::actingAs($this->abcAdmin);

        $this->postJson("/api/realty/offers/{$offer->id}/approval", ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('pending', $offer->fresh()->approval_status);
    }

    public function test_a_brokers_admin_making_custom_terms_still_waits_for_johndorf(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->abcAdmin);

        $this->postJson('/api/realty/offers', $this->form(['custom' => true, 'custom_milestones' => $this->terms()]))
            ->assertCreated()
            ->assertJsonPath('approval_status', 'pending');
        Mail::assertSent(ApprovalRequestMail::class, fn (ApprovalRequestMail $m) => $m->hasTo($this->admin->email));
    }

    public function test_johndorfs_admin_approves_and_the_brokers_agent_is_sent_to_their_own_dashboard(): void
    {
        Mail::fake();
        $offer = $this->offer($this->abcAgent, ['approval_status' => 'pending', 'custom_milestones' => $this->terms()]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/offers/{$offer->id}/approval", ['decision' => 'approve'])->assertOk()->assertJsonPath('approval_status', 'approved');

        Mail::assertSent(ApprovalResultMail::class, fn (ApprovalResultMail $m) => $m->hasTo($this->abcAgent->email) && str_contains($m->dashboardUrl, "/abc-realty/dashboard/offers/{$offer->id}"));
    }

    public function test_a_broker_cannot_rewrite_terms_of_a_colleagues_offer_but_can_fix_its_own(): void
    {
        $theirs = $this->offer($this->abcOtherAgent, ['approval_status' => 'rejected', 'custom_milestones' => $this->terms()]);
        $mine = $this->offer($this->abcAgent, ['approval_status' => 'rejected', 'custom_milestones' => $this->terms()]);

        Sanctum::actingAs($this->abcAdmin);
        $this->postJson("/api/realty/offers/{$theirs->id}/terms", ['custom_milestones' => $this->terms()])->assertForbidden();

        Sanctum::actingAs($this->abcAgent);
        $this->postJson("/api/realty/offers/{$mine->id}/terms", ['custom_milestones' => $this->terms()])->assertOk()->assertJsonPath('approval_status', 'pending');
    }

    // ----- a broker never edits the developer's side -----

    public function test_a_brokers_admin_cannot_edit_the_inventory_or_set_unit_status_or_check_buyer_files(): void
    {
        $offer = $this->offer($this->abcAdmin);
        $doc = OfferDocument::create(['offer_id' => $offer->id, 'realty_id' => $this->johndorf->id, 'path' => 'x/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10, 'status' => 'pending']);
        Sanctum::actingAs($this->abcAdmin);

        $this->postJson('/api/realty/projects', ['name' => 'My own project'])->assertForbidden();
        $this->postJson("/api/realty/projects/{$this->project->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->postJson("/api/realty/projects/{$this->project->id}/units", ['name' => 'Lot 99', 'category' => 'Residential'])->assertForbidden();
        $this->postJson("/api/realty/units/{$this->unit->id}", ['name' => 'Lot 1', 'category' => 'Residential'])->assertForbidden();
        $this->postJson("/api/realty/projects/{$this->project->id}/plans", ['name' => 'Cash', 'milestones' => [['label' => 'Full', 'percent' => 100, 'days' => 0]]])->assertForbidden();
        $this->postJson('/api/realty/requirements', ['name' => 'Junk'])->assertForbidden();
        $this->getJson('/api/realty/requirements')->assertForbidden();
        $this->postJson("/api/realty/offers/{$offer->id}/unit-status", ['status' => 'sold'])->assertForbidden();
        $this->postJson("/api/realty/offers/{$offer->id}/documents/{$doc->id}/review", ['status' => 'approved'])->assertForbidden();

        $this->assertSame('pending', $doc->fresh()->status);
        $this->assertSame('available', $this->unit->fresh()->status);
        $this->assertSame(1, Project::count());
    }

    public function test_a_broker_agent_cannot_check_buyer_files_while_a_johndorf_agent_checks_their_own(): void
    {
        $mine = $this->offer($this->johndorfAgent);
        $theirs = $this->offer($this->abcAgent);
        $doc = fn (Offer $o) => OfferDocument::create(['offer_id' => $o->id, 'realty_id' => $this->johndorf->id, 'path' => 'x/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10, 'status' => 'pending']);
        $mineDoc = $doc($mine);
        $theirDoc = $doc($theirs);

        Sanctum::actingAs($this->abcAgent);
        $this->postJson("/api/realty/offers/{$theirs->id}/documents/{$theirDoc->id}/review", ['status' => 'approved'])->assertForbidden();

        Sanctum::actingAs($this->johndorfAgent);
        $this->postJson("/api/realty/offers/{$mine->id}/documents/{$mineDoc->id}/review", ['status' => 'approved'])->assertOk();
        // ...but not a broker's offer, which a Johndorf agent cannot even see.
        $this->postJson("/api/realty/offers/{$theirs->id}/documents/{$theirDoc->id}/review", ['status' => 'approved'])->assertNotFound();
    }

    public function test_johndorfs_admin_can_still_do_everything_it_could_before(): void
    {
        $offer = $this->offer($this->abcAgent);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/offers/{$offer->id}/unit-status", ['status' => 'reserved'])->assertOk();
        $this->postJson("/api/realty/projects/{$this->project->id}", ['name' => 'Montierra Phase 2'])->assertOk();
        $this->postJson('/api/realty/requirements', ['name' => 'Valid ID', 'applies' => 'all'])->assertSuccessful();
    }

    public function test_a_broker_cannot_use_the_assistant_but_a_johndorf_agent_can(): void
    {
        Sanctum::actingAs($this->abcAdmin);
        $this->getJson('/api/realty/assistant/chats')->assertForbidden();
        Sanctum::actingAs($this->abcAgent);
        $this->getJson('/api/realty/assistant/chats')->assertForbidden();

        Sanctum::actingAs($this->johndorfAgent);
        $this->getJson('/api/realty/assistant/chats')->assertOk();
    }

    // ----- who sees which offer -----

    public function test_each_side_sees_only_the_offers_it_should(): void
    {
        $inHouse = $this->offer($this->johndorfAgent);
        $abcOne = $this->offer($this->abcAgent);
        $abcTwo = $this->offer($this->abcOtherAgent);
        $xyz = $this->offer($this->xyzAgent);

        $ids = fn () => collect($this->getJson('/api/realty/offers')->assertOk()->json())->pluck('id')->sort()->values()->all();

        Sanctum::actingAs($this->admin);
        $this->assertSame([$inHouse->id, $abcOne->id, $abcTwo->id, $xyz->id], $ids());

        Sanctum::actingAs($this->abcAdmin);
        $this->assertSame([$abcOne->id, $abcTwo->id], $ids());
        $this->getJson("/api/realty/offers/{$xyz->id}")->assertNotFound();
        $this->getJson("/api/realty/offers/{$inHouse->id}")->assertNotFound();

        Sanctum::actingAs($this->abcAgent);
        $this->assertSame([$abcOne->id], $ids());
        $this->getJson("/api/realty/offers/{$abcTwo->id}")->assertNotFound();

        Sanctum::actingAs($this->johndorfAgent);
        $this->assertSame([$inHouse->id], $ids());
        $this->getJson("/api/realty/offers/{$abcOne->id}")->assertNotFound();
    }

    public function test_the_offer_list_says_which_firm_sold_it(): void
    {
        $this->offer($this->abcAgent);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/realty/offers')->assertJsonPath('0.broker', 'ABC Realty')->assertJsonPath('0.broker_id', $this->abc->id);
    }

    public function test_a_broker_can_void_its_own_firms_offers_and_a_johndorf_agent_cannot_void_a_brokers(): void
    {
        $own = $this->offer($this->abcAgent);
        $colleagues = $this->offer($this->abcOtherAgent);
        $other = $this->offer($this->xyzAgent);

        Sanctum::actingAs($this->abcAgent);
        $this->postJson("/api/realty/offers/{$own->id}/void")->assertOk();
        $this->postJson("/api/realty/offers/{$colleagues->id}/void")->assertNotFound();

        Sanctum::actingAs($this->abcAdmin);
        $this->postJson("/api/realty/offers/{$colleagues->id}/void")->assertOk();
        $this->postJson("/api/realty/offers/{$other->id}/void")->assertNotFound();

        Sanctum::actingAs($this->johndorfAgent);
        $this->postJson("/api/realty/offers/{$other->id}/void")->assertNotFound();
    }

    public function test_a_broker_sees_that_a_unit_is_taken_but_not_another_firms_sale(): void
    {
        $theirs = $this->offer($this->xyzAgent, ['buyer_name' => 'Secret Buyer']);
        $this->unit->update(['status' => 'reserved', 'status_offer_id' => $theirs->id, 'status_by_id' => $this->admin->id, 'status_at' => now()]);

        Sanctum::actingAs($this->abcAgent);
        $this->getJson("/api/realty/projects/{$this->project->id}")
            ->assertJsonPath('units.0.status', 'reserved')
            ->assertJsonPath('units.0.status_detail.offer_code', null)
            ->assertJsonPath('units.0.status_detail.offer_id', null)
            ->assertJsonPath('units.0.status_detail.agent', null)
            ->assertJsonPath('units.0.status_detail.buyer', null);

        // The broker's own firm sees its own sale (never the buyer's name unless it is the admin of the developer).
        $mine = $this->offer($this->abcAgent);
        $this->otherUnit->update(['status' => 'reserved', 'status_offer_id' => $mine->id, 'status_by_id' => $this->admin->id, 'status_at' => now()]);
        $detail = $this->getJson("/api/realty/projects/{$this->project->id}")->json('units.1.status_detail');
        $this->assertSame($mine->code, $detail['offer_code']);
        $this->assertSame('Ana Cruz', $detail['agent']);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/realty/projects/{$this->project->id}")
            ->assertJsonPath('units.0.status_detail.buyer', 'Secret Buyer')
            ->assertJsonPath('units.0.status_detail.agent', 'Xavi Agent');
    }

    public function test_responses_and_the_overview_follow_the_same_visibility(): void
    {
        $abcOffer = $this->offer($this->abcAgent);
        $xyzOffer = $this->offer($this->xyzAgent);
        foreach ([$abcOffer, $xyzOffer] as $o) {
            $o->responses()->create(['realty_id' => $this->johndorf->id, 'kind' => 'interested', 'name' => 'Buyer', 'ip' => '127.0.0.1']);
        }

        Sanctum::actingAs($this->abcAdmin);
        $this->getJson('/api/realty/offers/responses')->assertJsonCount(1);
        $this->getJson('/api/realty/overview')
            ->assertJsonPath('stats.offers', 1)
            ->assertJsonPath('stats.new_responses', 1)
            ->assertJsonPath('stats.projects', 1)
            ->assertJsonPath('stats.units', 2)
            ->assertJsonPath('stats.docs_to_review', 0)
            ->assertJsonPath('stats.to_approve', 0);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/realty/offers/responses')->assertJsonCount(2);
        $this->getJson('/api/realty/overview')->assertJsonPath('stats.offers', 2)->assertJsonPath('stats.new_responses', 2);
    }

    public function test_the_developer_opening_a_brokers_offer_does_not_clear_the_agents_new_flags(): void
    {
        $offer = $this->offer($this->abcAgent);
        $offer->responses()->create(['realty_id' => $this->johndorf->id, 'kind' => 'question', 'name' => 'Buyer', 'ip' => '127.0.0.1']);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/realty/offers/{$offer->id}")->assertOk();
        $this->assertNull($offer->responses()->first()->seen_at);

        Sanctum::actingAs($this->abcAgent);
        $this->getJson("/api/realty/offers/{$offer->id}")->assertOk();
        $this->assertNotNull($offer->responses()->first()->seen_at);
    }

    // ----- the buyer's page -----

    public function test_the_buyers_page_treats_the_selling_firm_as_insiders_and_nobody_else(): void
    {
        $offer = $this->offer($this->abcAgent, ['access_username' => 'buyer', 'access_password' => 'secret1']);

        // A private offer shows a stranger only the sign-in stub...
        $this->getJson("/api/offers/{$offer->code}")->assertOk()->assertJsonPath('locked', true)->assertJsonMissingPath('buyer_name');

        // ...and the people who may see it in their dashboard the whole offer, no login, without counting as a buyer view.
        foreach ([$this->abcAdmin, $this->admin, $this->abcAgent] as $insider) {
            Sanctum::actingAs($insider);
            $this->getJson("/api/offers/{$offer->code}")->assertOk()->assertJsonPath('private', true)->assertJsonPath('broker.name', 'ABC Realty');
        }
        $this->assertSame(0, $offer->fresh()->views);

        // Another firm's agent, a Johndorf agent and a colleague of the offer's agent are strangers.
        foreach ([$this->xyzAgent, $this->johndorfAgent, $this->abcOtherAgent] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/offers/{$offer->code}")->assertOk()->assertJsonPath('locked', true)->assertJsonMissingPath('buyer_name');
        }
    }

    public function test_the_buyers_reply_goes_to_the_agents_own_dashboard(): void
    {
        Mail::fake();
        $offer = $this->offer($this->abcAgent, ['access_username' => null, 'access_password' => null]);

        $this->postJson("/api/offers/{$offer->code}/respond", ['kind' => 'interested', 'name' => 'Buyer', 'phone' => '09171234567'])->assertCreated();

        Mail::assertSent(OfferResponseMail::class, fn ($m) => $m->hasTo($this->abcAgent->email) && str_contains($m->dashboardUrl, "/abc-realty/dashboard/offers/{$offer->id}"));
    }

    // ----- sign-in rules -----

    public function test_an_account_with_a_temporary_password_cannot_use_the_dashboard_until_it_is_changed(): void
    {
        $this->abcAdmin->forceFill(['must_change_password' => true])->save();
        Sanctum::actingAs($this->abcAdmin);

        $this->getJson('/api/realty/overview')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('must_change_password', true);
    }

    public function test_an_inactive_realty_has_no_dashboard_and_no_sign_in(): void
    {
        $this->abc->update(['status' => Realty::STATUS_INVITED]);

        $this->postJson('/api/auth/login', ['email' => $this->abcAdmin->email, 'password' => 'password', 'realty' => 'abc-realty'])->assertUnprocessable();
        Sanctum::actingAs($this->abcAdmin);
        $this->getJson('/api/realty/overview')->assertForbidden();
    }

    public function test_a_broker_signs_in_at_its_own_address_and_not_at_johndorfs(): void
    {
        $this->postJson('/api/auth/login', ['email' => $this->abcAdmin->email, 'password' => 'password', 'realty' => 'abc-realty'])
            ->assertOk()
            ->assertJsonPath('user.realty.kind', 'broker')
            ->assertJsonPath('user.realty.developer.slug', 'johndorf');
        $this->postJson('/api/auth/login', ['email' => $this->abcAdmin->email, 'password' => 'password', 'realty' => 'johndorf'])->assertUnprocessable();
    }

    public function test_brokers_are_not_listed_on_the_public_platform_page(): void
    {
        $slugs = collect($this->getJson('/api/realties')->assertOk()->json())->pluck('slug')->all();

        $this->assertSame(['johndorf'], $slugs);
        $this->getJson('/api/realties/abc-realty')->assertOk()->assertJsonPath('kind', 'broker')->assertJsonPath('developer.name', 'Johndorf Ventures Corporation');
    }

    public function test_agents_are_managed_by_each_realtys_own_admins(): void
    {
        Sanctum::actingAs($this->abcAdmin);
        $this->getJson('/api/realty/agents')->assertOk()->assertJsonCount(2, 'agents');

        $this->postJson('/api/realty/agents', ['name' => 'New Agent', 'phone' => '09171234567'])->assertCreated();
        $this->assertDatabaseHas('agent_invitations', ['realty_id' => $this->abc->id, 'name' => 'New Agent']);

        Sanctum::actingAs($this->abcAgent);
        $this->getJson('/api/realty/agents')->assertForbidden();
    }

    // ----- helpers -----

    private function broker(string $name, string $slug): Realty
    {
        return Realty::create([
            'name' => $name, 'slug' => $slug, 'kind' => Realty::KIND_BROKER, 'developer_id' => $this->johndorf->id,
            'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(),
        ]);
    }

    private function person(Realty $realty, string $role, string $name): User
    {
        return User::factory()->create(['role' => $role, 'realty_id' => $realty->id, 'name' => $name]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'unit_id' => $this->unit->id,
            'buyer_name' => 'Juliecor Repompo',
            'purchase_date' => now()->toDateString(),
            'access_username' => 'Juliecor',
            'access_password' => 'Repompo',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function terms(): array
    {
        return [['label' => 'Reservation', 'percent' => 10, 'days' => 0], ['label' => 'Balance', 'percent' => 90, 'days' => 30]];
    }

    /** @param  array<string, mixed>  $overrides */
    private function offer(User $agent, array $overrides = []): Offer
    {
        return Offer::create($overrides + [
            'realty_id' => $this->johndorf->id,
            'broker_realty_id' => $agent->realty->isBroker() ? $agent->realty_id : null,
            'project_id' => $this->project->id,
            'unit_id' => $this->unit->id,
            'agent_id' => $agent->id,
            'code' => Offer::newCode(),
            'buyer_name' => 'Juliecor Repompo',
            'purchase_date' => now()->toDateString(),
            'price' => 2800000,
            'schedule' => [],
            'status' => 'active',
        ]);
    }
}
