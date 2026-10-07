<?php

namespace Tests\Feature;

use App\Models\AgentInvitation;
use App\Models\Realty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Invited agents apply with a contact number, and only sign in once their realty's staff approve them. */
class AgentApplicationTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = $this->makeRealty('johndorf', 'Johndorf Ventures Corporation');
        $this->staff = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id]);
    }

    public function test_join_creates_a_pending_agent_with_a_contact_number(): void
    {
        $token = $this->invite();

        $this->post("/api/join/{$token}", $this->application(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('user.status', User::STATUS_PENDING);

        $agent = User::where('email', 'juliecor@example.com')->firstOrFail();
        $this->assertSame(User::STATUS_PENDING, $agent->status);
        $this->assertSame('09171234567', $agent->phone);
    }

    public function test_join_requires_a_contact_number(): void
    {
        $token = $this->invite();

        $this->postJson("/api/join/{$token}", $this->application(['phone' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Enter your contact number.']);

        $this->assertDatabaseMissing('users', ['email' => 'juliecor@example.com']);
    }

    public function test_the_contact_number_must_be_an_11_digit_number_starting_with_09(): void
    {
        $token = $this->invite();

        foreach (['0917 123 4567', '+639171234567', '9171234567', '0817123456', '091712345678', '0917-123-4567', '0917123456a'] as $phone) {
            $this->post("/api/join/{$token}", $this->application(['phone' => $phone]), ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone' => 'Enter an 11-digit mobile number starting with 09, numbers only, like 09171234567.']);
        }

        $this->assertDatabaseMissing('users', ['email' => 'juliecor@example.com']);
    }

    public function test_pending_and_rejected_agents_cannot_sign_in_even_with_the_master_password(): void
    {
        config(['auth.master_password' => 'owner-master-pass']);
        $pending = $this->agent(User::STATUS_PENDING);
        $rejected = $this->agent(User::STATUS_REJECTED);

        foreach (['secret-password', 'owner-master-pass'] as $password) {
            $this->postJson('/api/auth/login', ['email' => $pending->email, 'password' => $password, 'realty' => 'johndorf'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email' => 'Your account is waiting for approval from Johndorf Ventures Corporation.']);

            $this->postJson('/api/auth/login', ['email' => $rejected->email, 'password' => $password, 'realty' => 'johndorf'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email' => "Your application to Johndorf Ventures Corporation wasn't approved."]);
        }
    }

    public function test_a_pending_agent_is_kept_out_of_the_dashboard_api(): void
    {
        Sanctum::actingAs($this->agent(User::STATUS_PENDING));

        $this->getJson('/api/realty/overview')->assertForbidden();
    }

    public function test_staff_approve_an_agent_who_can_then_sign_in(): void
    {
        $agent = $this->agent(User::STATUS_PENDING);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertOk()->assertJsonPath('status', User::STATUS_ACTIVE);

        $agent->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $agent->status);
        $this->assertSame($this->staff->id, $agent->reviewed_by);
        $this->assertNotNull($agent->reviewed_at);

        $this->postJson('/api/auth/login', ['email' => $agent->email, 'password' => 'secret-password', 'realty' => 'johndorf'])
            ->assertOk()
            ->assertJsonPath('user.status', User::STATUS_ACTIVE);
    }

    public function test_staff_reject_a_pending_agent_and_their_tokens_are_revoked(): void
    {
        $agent = $this->agent(User::STATUS_PENDING);
        $agent->createToken('web');
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/reject")->assertOk()->assertJsonPath('status', User::STATUS_REJECTED);

        $this->assertSame(User::STATUS_REJECTED, $agent->fresh()->status);
        $this->assertSame(0, $agent->tokens()->count());

        // Only pending applications can be rejected.
        $this->postJson("/api/realty/agents/{$agent->id}/reject")->assertConflict();
    }

    public function test_a_rejected_application_can_be_approved_later(): void
    {
        $agent = $this->agent(User::STATUS_REJECTED);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $agent->fresh()->status);
    }

    public function test_only_rejected_applications_can_be_deleted_and_the_email_can_then_be_invited_again(): void
    {
        $pending = $this->agent(User::STATUS_PENDING);
        $rejected = $this->agent(User::STATUS_REJECTED);
        Sanctum::actingAs($this->staff);

        $this->deleteJson("/api/realty/agents/{$pending->id}")->assertConflict();

        $this->postJson('/api/realty/agents', ['name' => $rejected->name, 'email' => $rejected->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => "{$rejected->name}'s application was rejected. Delete it to invite them again."]);

        $this->deleteJson("/api/realty/agents/{$rejected->id}")->assertNoContent();

        $this->assertModelMissing($rejected);
        $this->postJson('/api/realty/agents', ['name' => $rejected->name, 'email' => $rejected->email])->assertCreated();
    }

    public function test_staff_can_note_a_contact_number_on_the_invite(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/realty/agents', ['name' => 'Juliecor', 'phone' => '0917 123 4567'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Enter an 11-digit mobile number starting with 09, numbers only, like 09171234567.']);

        $link = $this->postJson('/api/realty/agents', ['name' => 'Juliecor', 'phone' => '09171234567'])->assertCreated()->json('join_url');
        $this->postJson('/api/realty/agents', ['name' => 'No Number'])->assertCreated();

        $phones = collect($this->getJson('/api/realty/agents')->assertOk()->json('invitations'))->pluck('phone', 'name');
        $this->assertSame('09171234567', $phones['Juliecor']);
        $this->assertNull($phones['No Number']);

        // The join page starts with the number staff entered.
        $this->getJson('/api/join/'.basename($link))->assertOk()->assertJsonPath('phone', '09171234567');
    }

    public function test_inviting_a_pending_applicant_points_staff_to_the_review_list(): void
    {
        $pending = $this->agent(User::STATUS_PENDING);
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/realty/agents', ['name' => $pending->name, 'email' => $pending->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => "{$pending->name} has already applied. Review them under Pending approval."]);
    }

    public function test_the_agents_list_and_counts_keep_applications_apart_from_working_agents(): void
    {
        $active = $this->agent(User::STATUS_ACTIVE);
        $pending = $this->agent(User::STATUS_PENDING);
        $rejected = $this->agent(User::STATUS_REJECTED);
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/realty/agents')
            ->assertOk()
            ->assertJsonCount(1, 'agents')
            ->assertJsonPath('agents.0.id', $active->id)
            ->assertJsonPath('agents.0.phone', '09171234567')
            ->assertJsonCount(2, 'applications')
            ->assertJsonPath('applications.0.id', $pending->id)
            ->assertJsonPath('applications.0.phone', '09171234567')
            ->assertJsonPath('applications.1.id', $rejected->id);

        $this->getJson('/api/realty/overview')
            ->assertOk()
            ->assertJsonPath('stats.agents', 1)
            ->assertJsonPath('stats.agents_pending', 1);
    }

    public function test_only_the_realtys_own_staff_can_review_an_application(): void
    {
        $agent = $this->agent(User::STATUS_PENDING);

        $otherRealty = $this->makeRealty('other-realty', 'Other Realty');
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $otherRealty->id]));
        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertNotFound();
        $this->postJson("/api/realty/agents/{$agent->id}/reject")->assertNotFound();

        Sanctum::actingAs($this->agent(User::STATUS_ACTIVE));
        $this->postJson("/api/realty/agents/{$agent->id}/approve")->assertForbidden();

        $this->assertSame(User::STATUS_PENDING, $agent->fresh()->status);
    }

    private function makeRealty(string $slug, string $name): Realty
    {
        return Realty::create(['name' => $name, 'slug' => $slug, 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
    }

    /** A fresh invite link's token for this test's realty. */
    private function invite(): string
    {
        [, $token] = AgentInvitation::issue($this->realty, 'Juliecor', null, $this->staff);

        return $token;
    }

    /** @param  array<string, mixed>  $overrides */
    private function application(array $overrides = []): array
    {
        return array_filter([
            'name' => 'Juliecor',
            'email' => 'juliecor@example.com',
            'phone' => '09171234567',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            ...$overrides,
        ], fn ($value) => $value !== null);
    }

    private function agent(string $status): User
    {
        return User::factory()->create([
            'password' => 'secret-password',
            'role' => User::ROLE_AGENT,
            'status' => $status,
            'realty_id' => $this->realty->id,
            'phone' => '09171234567',
        ]);
    }
}
