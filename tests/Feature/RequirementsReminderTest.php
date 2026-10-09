<?php

namespace Tests\Feature;

use App\Mail\RequirementsReminderMail;
use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\RequirementType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The reminder email: branded HTML, the items still missing, and a link that signs the buyer straight in. */
class RequirementsReminderTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $agent;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://jvconline.ph']);
        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(), 'accent_color' => '#b4241c', 'logo_path' => '/johndorf/logo.png']);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ana Cruz']);
        $project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Astana Davao', 'status' => 'active']);
        $this->unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'name' => 'Two-Storey Townhouse', 'price' => 3300000, 'status' => 'available']);
        RequirementType::create(['realty_id' => $this->realty->id, 'name' => 'Valid government ID', 'help' => 'Front and back.', 'applies' => 'all', 'sort' => 1]);
        RequirementType::create(['realty_id' => $this->realty->id, 'name' => 'Proof of income', 'applies' => 'all', 'sort' => 2]);
    }

    public function test_the_reminder_is_a_branded_html_email_listing_what_is_missing(): void
    {
        $offer = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6']);
        $html = $this->render(new RequirementsReminderMail($offer, $this->todo($offer), true));

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Requirements reminder', $html);
        $this->assertStringContainsString("You're almost there, John", $html);
        $this->assertStringContainsString('Two-Storey Townhouse, Astana Davao', $html);
        foreach (['Your details', 'Valid government ID', 'Proof of income', 'Complete my requirements'] as $item) {
            $this->assertStringContainsString($item, $html);
        }
        $this->assertStringContainsString('https://jvconline.ph/johndorf/logo.png', $html);
        $this->assertStringContainsString('#b4241c', $html);
        // The login itself never goes in the email.
        $this->assertStringNotContainsString('ZC423KG6', $html);
    }

    public function test_a_sent_back_document_shows_the_agents_reason(): void
    {
        $offer = $this->offer();
        $type = RequirementType::where('name', 'Valid government ID')->first();
        $offer->documents()->create(['realty_id' => $this->realty->id, 'requirement_type_id' => $type->id, 'path' => 'x/id.jpg', 'original_name' => 'id.jpg', 'mime' => 'image/jpeg', 'size' => 10, 'status' => 'rejected', 'note' => 'The photo is blurry']);
        $offer->load('documents');

        $html = $this->render(new RequirementsReminderMail($offer, $this->todo($offer), false));

        $this->assertStringContainsString('Sent back', $html);
        $this->assertStringContainsString('The photo is blurry', $html);
        $this->assertStringNotContainsString('Your details', $html);
    }

    public function test_a_private_offers_link_signs_the_buyer_in_and_a_public_offers_just_opens(): void
    {
        $private = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6']);
        $this->assertMatchesRegularExpression('#^https://jvconline\.ph/offer/'.$private->code.'/enter\?k=\d+\.[a-f0-9]{64}$#', $private->requirementsUrl());

        $public = $this->offer();
        $this->assertSame("https://jvconline.ph/offer/{$public->code}?guide=1#requirements", $public->requirementsUrl());
    }

    public function test_the_text_version_has_the_link_and_the_items(): void
    {
        $offer = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6']);
        $text = view('mail.requirements-reminder-text', ['offer' => $offer->load(['unit', 'project', 'realty', 'agent']), 'todo' => $this->todo($offer), 'needsDetails' => true, 'url' => $offer->requirementsUrl(), 'days' => 30])->render();

        $this->assertStringContainsString('Complete my requirements: https://jvconline.ph/offer/'.$offer->code.'/enter?k=', $text);
        $this->assertStringContainsString('- Valid government ID', $text);
        $this->assertStringNotContainsString('ZC423KG6', $text);
    }

    public function test_sending_a_reminder_mails_the_buyer_with_a_working_entry_link(): void
    {
        Mail::fake();
        $offer = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6', 'buyer_email' => 'john@example.com']);

        Sanctum::actingAs($this->agent);
        $this->postJson("/api/realty/offers/{$offer->id}/remind")->assertOk()->assertJsonPath('sent_to', 'john@example.com');

        Mail::assertSent(RequirementsReminderMail::class, fn (RequirementsReminderMail $m) => $m->hasTo('john@example.com'));
    }

    // ----- the entry key -----

    public function test_the_entry_key_signs_the_buyer_in_without_the_password(): void
    {
        $offer = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6']);
        $key = $offer->entryToken();

        $token = $this->postJson("/api/offers/{$offer->code}/enter", ['key' => $key])->assertOk()->json('token');

        $this->assertSame($offer->accessToken(), $token);
        $this->getJson("/api/offers/{$offer->code}", ['X-Offer-Access' => $token])->assertOk()->assertJsonPath('buyer_name', 'John Maizo');
    }

    public function test_a_wrong_tampered_or_expired_key_is_refused(): void
    {
        $offer = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6']);
        $other = $this->offer(['access_username' => 'Jane', 'access_password' => 'AB123456']);

        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => $other->entryToken()])->assertUnprocessable();
        [$expires, $signature] = explode('.', $offer->entryToken());
        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => ($expires + 999999).'.'.$signature])->assertUnprocessable();

        $this->travel(Offer::ENTRY_DAYS + 1)->days();
        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => $offer->entryToken()])->assertOk(); // a fresh one, made now, works
        $this->travelBack();
        $key = $offer->entryToken();
        $this->travel(Offer::ENTRY_DAYS + 1)->days();
        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => $key])->assertUnprocessable();
    }

    public function test_changing_the_buyers_login_kills_the_old_emailed_links(): void
    {
        $offer = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6']);
        $key = $offer->entryToken();

        $offer->update(['access_password' => 'NEWPASS99']);

        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => $key])->assertUnprocessable();
    }

    public function test_a_void_offer_cannot_be_entered(): void
    {
        $offer = $this->offer(['access_username' => 'John', 'access_password' => 'ZC423KG6']);
        $key = $offer->entryToken();
        $offer->update(['status' => 'void']);

        $this->postJson("/api/offers/{$offer->code}/enter", ['key' => $key])->assertStatus(410);
    }

    /** @return array<int, array<string, mixed>> */
    private function todo(Offer $offer): array
    {
        return collect($offer->fresh(['documents'])->requirementList())->where('needed', 'required')->whereIn('state', ['missing', 'rejected'])->values()->all();
    }

    private function render(RequirementsReminderMail $mail): string
    {
        return $mail->render();
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
            'buyer_name' => 'John Maizo',
            'purchase_date' => now()->toDateString(),
            'price' => 3300000,
            'schedule' => [],
            'status' => 'active',
        ]);
    }
}
