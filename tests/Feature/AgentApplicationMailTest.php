<?php

namespace Tests\Feature;

use App\Mail\AgentApplicationReceivedMail;
use App\Mail\AgentApprovedMail;
use App\Mail\AgentRejectedMail;
use App\Models\AgentInvitation;
use App\Models\Realty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who hears about an agent's application: the realty's admins when it arrives, the agent when it is
 * approved or turned down. The applicant also stays signed in on a pending page, with a session that
 * reaches nothing but their own status.
 */
class AgentApplicationMailTest extends TestCase
{
    use RefreshDatabase;

    private Realty $johndorf;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://jvconline.ph']);
        $this->johndorf = Realty::create([
            'name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(),
            'logo_path' => '/johndorf/logo.png', 'accent_color' => '#b4241c',
        ]);
        $this->staff = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id, 'name' => 'Johndorf Admin', 'email' => 'admin@johndorf.test']);
    }

    // ----- the application arrives -----

    public function test_an_application_emails_every_active_admin_of_the_realty_and_nobody_else(): void
    {
        Mail::fake();
        $second = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id, 'email' => 'second@johndorf.test']);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->johndorf->id, 'email' => 'agent@johndorf.test']);
        $elsewhere = Realty::create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $outsider = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $elsewhere->id, 'email' => 'outsider@elsewhere.test']);

        $this->post('/api/join/'.$this->invite(), $this->application(), ['Accept' => 'application/json'])->assertCreated();

        Mail::assertSent(AgentApplicationReceivedMail::class, 2);
        Mail::assertSent(AgentApplicationReceivedMail::class, fn (AgentApplicationReceivedMail $m) => $m->hasTo($this->staff->email));
        Mail::assertSent(AgentApplicationReceivedMail::class, fn (AgentApplicationReceivedMail $m) => $m->hasTo($second->email));
        Mail::assertNotSent(AgentApplicationReceivedMail::class, fn (AgentApplicationReceivedMail $m) => $m->hasTo($agent->email) || $m->hasTo($outsider->email));
    }

    public function test_an_application_with_a_mistake_sends_no_email_and_creates_no_account(): void
    {
        Mail::fake();

        $this->post('/api/join/'.$this->invite(), $this->application(['phone' => '0917']), ['Accept' => 'application/json'])->assertUnprocessable();

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('users', ['email' => 'juliecor@example.com']);
    }

    public function test_an_invitation_link_cannot_apply_twice_and_sends_one_email_only(): void
    {
        Mail::fake();
        $token = $this->invite();

        $this->post("/api/join/{$token}", $this->application(), ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/join/{$token}", $this->application(['email' => 'another@example.com']), ['Accept' => 'application/json'])->assertStatus(410);

        Mail::assertSent(AgentApplicationReceivedMail::class, 1);
    }

    public function test_an_application_goes_through_when_the_staff_email_cannot_be_sent(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP is down'));

        $this->post('/api/join/'.$this->invite(), $this->application(), ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(User::STATUS_PENDING, User::where('email', 'juliecor@example.com')->firstOrFail()->status);
    }

    public function test_the_session_an_application_returns_reaches_only_the_applicants_own_status(): void
    {
        Mail::fake();
        $token = $this->post('/api/join/'.$this->invite(), $this->application(), ['Accept' => 'application/json'])->assertCreated()->json('token');

        $this->as($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('status', User::STATUS_PENDING)
            ->assertJsonPath('realty.slug', 'johndorf');
        $this->as($token)->getJson('/api/realty/overview')->assertForbidden();
        $this->as($token)->getJson('/api/realty/projects')->assertForbidden();
        $this->as($token)->getJson('/api/realty/offers')->assertForbidden();
        $this->as($token)->getJson('/api/realty/agents')->assertForbidden();
        $this->as($token)->getJson('/api/admin/stats')->assertForbidden();
    }

    public function test_the_applicants_session_opens_the_dashboard_once_they_are_approved(): void
    {
        Mail::fake();
        $token = $this->post('/api/join/'.$this->invite(), $this->application(), ['Accept' => 'application/json'])->json('token');
        $agent = User::where('email', 'juliecor@example.com')->firstOrFail();

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertOk();

        $this->as($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('status', User::STATUS_ACTIVE);
        $this->as($token)->getJson('/api/realty/overview')->assertOk()->assertJsonPath('realty.slug', 'johndorf');
    }

    // ----- the decision -----

    public function test_approving_emails_the_agent_where_to_sign_in(): void
    {
        Mail::fake();
        $agent = $this->pendingAgent();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertOk();

        Mail::assertSent(AgentApprovedMail::class, 1);
        Mail::assertSent(AgentApprovedMail::class, fn (AgentApprovedMail $m) => $m->hasTo($agent->email) && $m->hasReplyTo($this->staff->email)
            && str_contains($m->render(), 'jvconline.ph/johndorf/login'));
    }

    public function test_approving_a_brokers_agent_links_to_the_brokers_own_sign_in_under_johndorfs_brand(): void
    {
        Mail::fake();
        $broker = Realty::create([
            'name' => 'ABC Realty', 'slug' => 'abc-realty', 'kind' => Realty::KIND_BROKER, 'developer_id' => $this->johndorf->id,
            'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(),
        ]);
        $brokerAdmin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $broker->id]);
        $agent = $this->pendingAgent($broker);
        Sanctum::actingAs($brokerAdmin);

        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertOk();

        Mail::assertSent(AgentApprovedMail::class, function (AgentApprovedMail $m) {
            $html = $m->render();

            return str_contains($html, 'jvconline.ph/abc-realty/login')
                && str_contains($html, 'ABC Realty')
                && str_contains($html, 'src="https://jvconline.ph/johndorf/logo.png"');
        });
    }

    public function test_rejecting_emails_the_agent_and_ends_their_session(): void
    {
        Mail::fake();
        $agent = $this->pendingAgent();
        $agent->createToken('realty-web:johndorf');
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/reject")->assertOk()->assertJsonPath('status', User::STATUS_REJECTED);

        Mail::assertSent(AgentRejectedMail::class, 1);
        Mail::assertSent(AgentRejectedMail::class, fn (AgentRejectedMail $m) => $m->hasTo($agent->email));
        Mail::assertNotSent(AgentApprovedMail::class);
        $this->assertSame(0, $agent->tokens()->count());
    }

    public function test_a_second_rejection_is_refused_and_sends_no_second_email(): void
    {
        Mail::fake();
        $agent = $this->pendingAgent();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/reject")->assertOk();
        $this->postJson("/api/realty/agents/{$agent->id}/reject")->assertConflict();

        Mail::assertSent(AgentRejectedMail::class, 1);
    }

    public function test_approving_a_rejected_application_later_sends_the_approval_email(): void
    {
        Mail::fake();
        $agent = $this->pendingAgent(null, User::STATUS_REJECTED);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertOk();

        Mail::assertSent(AgentApprovedMail::class, fn (AgentApprovedMail $m) => $m->hasTo($agent->email));
    }

    public function test_an_agent_who_is_already_approved_gets_no_second_approval_email(): void
    {
        Mail::fake();
        $agent = $this->pendingAgent(null, User::STATUS_ACTIVE);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertConflict();

        Mail::assertNothingSent();
    }

    public function test_the_decision_stands_when_the_email_cannot_be_sent(): void
    {
        Mail::shouldReceive('to')->twice()->andThrow(new \RuntimeException('SMTP is down'));
        $toApprove = $this->pendingAgent();
        $toReject = $this->pendingAgent(null, User::STATUS_PENDING, 'second@example.com');
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$toApprove->id}/approve")->assertOk()->assertJsonPath('status', User::STATUS_ACTIVE);
        $this->postJson("/api/realty/agents/{$toReject->id}/reject")->assertOk()->assertJsonPath('status', User::STATUS_REJECTED);

        $this->assertSame(User::STATUS_ACTIVE, $toApprove->fresh()->status);
        $this->assertSame(User::STATUS_REJECTED, $toReject->fresh()->status);
    }

    public function test_another_realtys_admin_cannot_decide_and_nothing_is_sent(): void
    {
        Mail::fake();
        $agent = $this->pendingAgent();
        $elsewhere = Realty::create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $elsewhere->id]));

        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertNotFound();
        $this->postJson("/api/realty/agents/{$agent->id}/reject")->assertNotFound();

        Mail::assertNothingSent();
        $this->assertSame(User::STATUS_PENDING, $agent->fresh()->status);
    }

    // ----- what the emails say -----

    public function test_the_application_email_shows_who_applied_and_how_to_reach_them(): void
    {
        $agent = $this->pendingAgent();

        $mail = new AgentApplicationReceivedMail($agent, $this->johndorf);

        $mail->assertHasSubject("Juliecor applied to join {$this->johndorf->name}");
        $mail->assertHasReplyTo($agent->email);
        $mail->assertSeeInHtml('Juliecor wants to join your team');
        $mail->assertSeeInHtml('09171234567');
        $mail->assertSeeInHtml('Review the application');
        $mail->assertSeeInHtml('because you are an admin of Johndorf Ventures Corporation on jvconline');
        $mail->assertDontSeeInHtml('because of your accreditation');
        $mail->assertSeeInHtml('jvconline.ph/johndorf/dashboard/agents', false);
        $mail->assertSeeInHtml('src="https://jvconline.ph/johndorf/logo.png"', false);
        $mail->assertSeeInText($agent->email);
        $mail->assertSeeInText('https://jvconline.ph/johndorf/dashboard/agents');
    }

    public function test_the_approval_email_names_the_username_and_never_the_password(): void
    {
        $agent = $this->pendingAgent();

        $mail = new AgentApprovedMail($agent->load('reviewer'), $this->johndorf);

        $mail->assertHasSubject("You're approved: welcome to {$this->johndorf->name}");
        $mail->assertSeeInHtml("You're in, Juliecor", false);
        $mail->assertSeeInHtml($agent->email);
        $mail->assertSeeInHtml('Sign in to your dashboard');
        $mail->assertSeeInHtml('because you applied to join Johndorf Ventures Corporation');
        $mail->assertDontSeeInHtml('secret-password');
        $mail->assertSeeInText("Username: {$agent->email}");
        $mail->assertDontSeeInText('secret-password');
    }

    public function test_the_rejection_email_is_kind_and_points_to_the_realtys_contact_details_when_it_has_them(): void
    {
        $agent = $this->pendingAgent();
        $this->johndorf->update(['email' => 'hello@johndorf.test', 'phone' => '032 123 4567']);

        $mail = new AgentRejectedMail($agent, $this->johndorf->fresh());

        $mail->assertHasSubject("Your application to {$this->johndorf->name}");
        $mail->assertSeeInHtml('About your application');
        $mail->assertSeeInHtml('not to approve it at this time');
        $mail->assertSeeInHtml('because you applied to join Johndorf Ventures Corporation');
        $mail->assertSeeInHtml('hello@johndorf.test');
        $mail->assertSeeInHtml('032 123 4567');
        $mail->assertSeeInText('hello@johndorf.test');
    }

    public function test_the_rejection_email_still_reads_well_when_the_realty_has_no_contact_details(): void
    {
        $agent = $this->pendingAgent();

        $mail = new AgentRejectedMail($agent, $this->johndorf);

        $mail->assertSeeInHtml('You can reply to this email.');
        $mail->assertDontSeeInHtml('mailto:');
    }

    public function test_the_emails_escape_the_applicants_name_so_no_markup_runs(): void
    {
        $evil = "<script>alert('x')</script> <b>Bold</b>";
        $agent = $this->pendingAgent(null, User::STATUS_PENDING, 'evil@example.com', $evil);

        foreach ([new AgentApplicationReceivedMail($agent, $this->johndorf), new AgentApprovedMail($agent, $this->johndorf), new AgentRejectedMail($agent, $this->johndorf)] as $mail) {
            $mail->assertDontSeeInHtml("<script>alert('x')</script>", false);
            $mail->assertDontSeeInHtml('<b>Bold</b>', false);
        }
    }

    // ----- helpers -----

    /** A request signed in with a real token: a second request in one test would reuse the first one's user otherwise. */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function invite(): string
    {
        [, $token] = AgentInvitation::issue($this->johndorf, 'Juliecor', null, $this->staff);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function application(array $overrides = []): array
    {
        return [
            'name' => 'Juliecor',
            'email' => 'juliecor@example.com',
            'phone' => '09171234567',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            ...$overrides,
        ];
    }

    private function pendingAgent(?Realty $realty = null, string $status = User::STATUS_PENDING, string $email = 'juliecor@example.com', string $name = 'Juliecor'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password',
            'role' => User::ROLE_AGENT,
            'status' => $status,
            'realty_id' => ($realty ?? $this->johndorf)->id,
            'phone' => '09171234567',
        ]);
    }
}
