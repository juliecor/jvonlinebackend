<?php

namespace Tests\Feature;

use App\Mail\RequirementsReminderMail;
use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** An offer is valid for as long as the agent chose. After that the buyer's link, sign-in and answers stop until it is extended. */
class OfferExpiryTest extends TestCase
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

    public function test_the_agent_picks_how_long_the_offer_is_valid(): void
    {
        Sanctum::actingAs($this->agent);

        $this->postJson('/api/realty/offers', $this->form(['valid_hours' => 72]))->assertCreated();
        $this->postJson('/api/realty/offers', $this->form(['valid_hours' => 0]))->assertUnprocessable()->assertJsonValidationErrors('valid_hours');
        $this->postJson('/api/realty/offers', $this->form(['valid_hours' => 99999]))->assertUnprocessable()->assertJsonValidationErrors('valid_hours');

        $offer = Offer::firstOrFail();
        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $offer->expires_at->timestamp, 5);
    }

    public function test_leaving_it_out_means_the_offer_never_expires(): void
    {
        Sanctum::actingAs($this->agent);

        $this->postJson('/api/realty/offers', $this->form())->assertCreated();

        $offer = Offer::firstOrFail();
        $this->assertNull($offer->expires_at);
        $this->assertFalse($offer->isExpired());
    }

    public function test_the_buyer_sees_the_valid_until_date_before_it_expires(): void
    {
        $offer = $this->offer(['expires_at' => now()->addDay()]);

        $this->getJson("/api/offers/{$offer->code}")->assertOk()->assertJsonPath('locked', true)->assertJsonPath('sheet.expires_at', fn ($v) => $v !== null);
    }

    public function test_an_expired_offer_shuts_the_buyer_out_everywhere(): void
    {
        $offer = $this->offer(['expires_at' => now()->subMinute()]);
        $token = $offer->accessToken();

        $this->getJson("/api/offers/{$offer->code}")->assertStatus(410)->assertJsonPath('message', Offer::EXPIRED_MESSAGE);
        $this->getJson("/api/offers/{$offer->code}", ['X-Offer-Access' => $token])->assertStatus(410);
        $this->postJson("/api/offers/{$offer->code}/unlock", ['username' => 'Juliecor', 'password' => 'Repompo'])->assertStatus(410);
        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => $offer->entryToken()])->assertStatus(410);
        $this->postJson("/api/offers/{$offer->code}/respond", ['kind' => 'interested', 'name' => 'Juliecor', 'phone' => '09171234567'], ['X-Offer-Access' => $token])->assertStatus(410);
        $this->postJson("/api/offers/{$offer->code}/details", [], ['X-Offer-Access' => $token])->assertStatus(410);
    }

    public function test_the_session_ends_by_itself_when_the_time_runs_out(): void
    {
        $offer = $this->offer(['expires_at' => now()->addHours(3)]);
        $token = $this->postJson("/api/offers/{$offer->code}/unlock", ['username' => 'Juliecor', 'password' => 'Repompo'])->assertOk()->json('token');

        $this->getJson("/api/offers/{$offer->code}", ['X-Offer-Access' => $token])->assertOk()->assertJsonPath('buyer_name', 'Juliecor Repompo');

        $this->travel(3)->hours();
        $this->travel(1)->minutes();
        $this->getJson("/api/offers/{$offer->code}", ['X-Offer-Access' => $token])->assertStatus(410);
    }

    public function test_the_realtys_own_people_can_still_open_an_expired_offer(): void
    {
        $offer = $this->offer(['expires_at' => now()->subDay()]);

        Sanctum::actingAs($this->agent);
        $this->getJson("/api/offers/{$offer->code}")->assertOk()->assertJsonPath('code', $offer->code);
    }

    public function test_extending_opens_the_same_link_again(): void
    {
        $offer = $this->offer(['expires_at' => now()->subDay()]);
        $token = $offer->accessToken();
        $this->getJson("/api/offers/{$offer->code}", ['X-Offer-Access' => $token])->assertStatus(410);

        Sanctum::actingAs($this->agent);
        $this->postJson("/api/realty/offers/{$offer->id}/extend", ['valid_hours' => 168])->assertOk()->assertJsonPath('expired', false);

        $this->getJson("/api/offers/{$offer->code}", ['X-Offer-Access' => $token])->assertOk()->assertJsonPath('buyer_name', 'Juliecor Repompo');
        $this->assertEqualsWithDelta(now()->addHours(168)->timestamp, $offer->fresh()->expires_at->timestamp, 5);
    }

    public function test_only_the_offers_own_people_can_extend_and_a_void_offer_cannot_be_extended(): void
    {
        $offer = $this->offer(['expires_at' => now()->subDay()]);

        $other = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id]);
        Sanctum::actingAs($other);
        $this->postJson("/api/realty/offers/{$offer->id}/extend", ['valid_hours' => 24])->assertNotFound();

        Sanctum::actingAs($this->agent);
        $this->postJson("/api/realty/offers/{$offer->id}/extend", ['valid_hours' => 0])->assertUnprocessable();
        $offer->update(['status' => 'void']);
        $this->postJson("/api/realty/offers/{$offer->id}/extend", ['valid_hours' => 24])->assertUnprocessable();
    }

    public function test_the_dashboard_list_says_which_offers_have_expired(): void
    {
        $this->offer(['expires_at' => now()->subHour()]);
        $this->offer(['expires_at' => now()->addHour()]);
        $this->offer();

        Sanctum::actingAs($this->agent);
        $rows = collect($this->getJson('/api/realty/offers')->assertOk()->json());

        $this->assertSame(1, $rows->where('expired', true)->count());
        $this->assertSame(2, $rows->where('expired', false)->count());
    }

    public function test_an_expired_offer_cannot_be_emailed_or_reminded_until_extended(): void
    {
        Mail::fake();
        $offer = $this->offer(['expires_at' => now()->subHour(), 'buyer_email' => 'juliecor@example.com']);

        Sanctum::actingAs($this->agent);
        $this->postJson("/api/realty/offers/{$offer->id}/remind")->assertUnprocessable();
        $this->postJson("/api/realty/offers/{$offer->id}/send")->assertUnprocessable();
        Mail::assertNothingSent();

        $this->postJson("/api/realty/offers/{$offer->id}/extend", ['valid_hours' => 24])->assertOk();
        $this->postJson("/api/realty/offers/{$offer->id}/remind")->assertOk();
        Mail::assertSent(RequirementsReminderMail::class);
    }

    public function test_the_reminder_says_when_the_offer_closes(): void
    {
        config(['app.frontend_url' => 'https://jvconline.ph']);
        $offer = $this->offer(['expires_at' => now()->addDays(2)]);
        $offer->load(['unit', 'project', 'realty', 'agent']);

        $html = (new RequirementsReminderMail($offer, [], true))->render();

        $this->assertStringContainsString('Your offer is open until', $html);
        $this->assertStringContainsString('Philippine time', $html);
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

    /** @param  array<string, mixed>  $overrides */
    private function offer(array $overrides = []): Offer
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
