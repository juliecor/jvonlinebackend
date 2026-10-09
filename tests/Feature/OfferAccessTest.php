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

/** A private offer: the buyer types the username and password from the agent before the offer opens or takes an answer. */
class OfferAccessTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $agent;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'email' => 'agent@example.com']);
        $project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Montierra', 'status' => 'active']);
        $this->unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'name' => 'Lot 1', 'category' => 'Residential', 'price' => 2800000, 'status' => 'available']);
    }

    public function test_the_agent_sets_the_buyers_login_when_making_the_offer(): void
    {
        Sanctum::actingAs($this->agent);

        $this->postJson('/api/realty/offers', $this->offerForm())->assertCreated();
        $this->postJson('/api/realty/offers', $this->offerForm(['access_username' => '', 'access_password' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access_username', 'access_password']);

        $offer = Offer::firstOrFail();
        $this->assertTrue($offer->isLocked());
        $this->assertSame('Juliecor', $offer->access_username);
        $this->assertNotSame('Repompo', $offer->getRawOriginal('access_password'), 'The password is stored encrypted.');
        $this->getJson("/api/realty/offers/{$offer->id}")->assertJsonPath('access_password', 'Repompo');
    }

    public function test_a_private_offer_shows_only_the_sign_in_page_until_the_buyer_signs_in(): void
    {
        $offer = $this->privateOffer();

        $this->getJson("/api/offers/{$offer->code}")
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('realty.slug', 'johndorf')
            ->assertJsonMissingPath('buyer_name')
            ->assertJsonMissingPath('price');
        $this->assertSame(0, $offer->fresh()->views);

        $this->postJson("/api/offers/{$offer->code}/unlock", ['username' => 'Juliecor', 'password' => 'repompo'])->assertJsonValidationErrors('username');
        $token = $this->postJson("/api/offers/{$offer->code}/unlock", ['username' => ' juliecor ', 'password' => 'Repompo'])->assertOk()->json('token');

        $this->getJson("/api/offers/{$offer->code}", ['X-Offer-Access' => $token])
            ->assertOk()
            ->assertJsonPath('buyer_name', 'Juliecor Repompo')
            ->assertJsonMissingPath('locked');
        $this->assertSame(1, $offer->fresh()->views);
    }

    public function test_the_buyers_answers_need_the_sign_in_too(): void
    {
        $offer = $this->privateOffer();
        $answer = ['kind' => 'interested', 'name' => 'Juliecor Repompo', 'phone' => '09171234567'];

        $this->postJson("/api/offers/{$offer->code}/respond", $answer)->assertUnauthorized();
        $this->postJson("/api/offers/{$offer->code}/details", [])->assertUnauthorized();

        $token = $offer->accessToken();
        $this->postJson("/api/offers/{$offer->code}/respond", $answer, ['X-Offer-Access' => $token])->assertCreated();
    }

    public function test_a_new_password_signs_the_buyer_out_and_the_realty_never_needs_one(): void
    {
        $offer = $this->privateOffer();
        $old = $offer->accessToken();

        Sanctum::actingAs($this->agent);
        $this->postJson("/api/realty/offers/{$offer->id}/login", ['access_username' => 'Juliecor', 'access_password' => 'NewPass123'])->assertOk();

        $this->assertFalse($offer->fresh()->grantsAccess($old));
        // The agent opening the link sees the whole offer, no login.
        $this->getJson("/api/offers/{$offer->code}")->assertOk()->assertJsonPath('buyer_name', 'Juliecor Repompo')->assertJsonPath('private', true);
    }

    public function test_offers_made_before_logins_stay_open(): void
    {
        $offer = $this->privateOffer(['access_username' => null, 'access_password' => null]);

        $this->getJson("/api/offers/{$offer->code}")->assertOk()->assertJsonPath('buyer_name', 'Juliecor Repompo');
        $this->postJson("/api/offers/{$offer->code}/respond", ['kind' => 'question', 'name' => 'Juliecor', 'phone' => '09171234567', 'message' => 'Can I visit?'])->assertCreated();
        $this->postJson("/api/offers/{$offer->code}/respond", ['kind' => 'not_interested', 'name' => 'Juliecor', 'phone' => '09171234567'])->assertJsonValidationErrors('kind');
    }

    /** @param  array<string, mixed>  $overrides */
    private function offerForm(array $overrides = []): array
    {
        return $overrides + [
            'unit_id' => $this->unit->id,
            'buyer_name' => 'Juliecor Repompo',
            'purchase_date' => now()->toDateString(),
            'access_username' => 'Juliecor',
            'access_password' => 'Repompo',
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function privateOffer(array $overrides = []): Offer
    {
        return Offer::create($overrides + [
            'realty_id' => $this->realty->id,
            'project_id' => $this->unit->project_id,
            'unit_id' => $this->unit->id,
            'agent_id' => $this->agent->id,
            'code' => Offer::newCode(),
            'buyer_name' => 'Juliecor Repompo',
            'purchase_date' => now()->toDateString(),
            'price' => 2800000,
            'schedule' => [],
            'status' => 'active',
            'access_username' => 'Juliecor',
            'access_password' => 'Repompo',
        ]);
    }
}
