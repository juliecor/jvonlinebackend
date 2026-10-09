<?php

namespace Tests\Feature;

use App\Mail\RequirementsReminderMail;
use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The logo in a branded email travels inside the message, so a mail app that won't load remote pictures still shows it. */
class MailLogoTest extends TestCase
{
    use RefreshDatabase;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://jvconline.ph']);
        Cache::flush();
        $realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(), 'logo_path' => '/johndorf/logo.png']);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $realty->id]);
        $project = Project::create(['realty_id' => $realty->id, 'name' => 'Astana Davao', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $realty->id, 'project_id' => $project->id, 'name' => 'Townhouse', 'price' => 3300000, 'status' => 'available']);
        $this->offer = Offer::create(['realty_id' => $realty->id, 'project_id' => $project->id, 'unit_id' => $unit->id, 'agent_id' => $agent->id, 'code' => Offer::newCode(), 'buyer_name' => 'John Maizo', 'purchase_date' => now()->toDateString(), 'price' => 3300000, 'schedule' => [], 'status' => 'active']);
    }

    public function test_the_logo_is_sent_inside_the_email(): void
    {
        Http::fake(['jvconline.ph/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

        $raw = $this->send();

        $this->assertStringContainsString('src=3D"cid:brand-logo-', $raw);
        $this->assertStringNotContainsString('src=3D"https://jvconline.ph/johndorf/logo.png"', $raw);
        $this->assertStringContainsString('Content-Type: image/png', $raw);
        $this->assertStringContainsString('Content-Disposition: inline', $raw);
        // A file name would make Gmail show the logo as an attachment.
        $this->assertDoesNotMatchRegularExpression('/Content-Type: image\/png[^\n]*name=/i', $raw);
        $this->assertDoesNotMatchRegularExpression('/Content-Disposition: inline[^\n]*(file)?name=/i', $raw);
    }

    public function test_a_logo_that_cannot_be_fetched_stays_a_web_address(): void
    {
        Http::fake(['jvconline.ph/*' => Http::response('nope', 404)]);

        $raw = $this->send();

        $this->assertStringContainsString('https://jvconline.ph/johndorf/logo.png', $raw);
        $this->assertStringNotContainsString('cid:brand-logo-', $raw);
    }

    public function test_something_that_is_not_a_picture_is_not_embedded(): void
    {
        Http::fake(['jvconline.ph/*' => Http::response('<html>challenge</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertStringNotContainsString('cid:brand-logo-', $this->send());
    }

    public function test_the_picture_is_fetched_once_and_reused(): void
    {
        Http::fake(['jvconline.ph/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

        $this->send();
        $this->send();

        Http::assertSentCount(1);
    }

    private function send(): string
    {
        Mail::to('john@example.com')->send(new RequirementsReminderMail($this->offer, [], true));
        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last();

        return $sent->getMessage()->toString();
    }
}
