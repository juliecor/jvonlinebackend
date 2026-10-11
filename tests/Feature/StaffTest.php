<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use App\Support\Assistant\RealtyTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** A realty's full admins make staff accounts and choose what each may use; the dashboard and the AI follow. */
class StaffTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->owner = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'name' => 'Johndorf Admin']);
    }

    public function test_a_full_admin_makes_a_staff_account_that_only_reaches_its_sections(): void
    {
        $this->asOwner('post', '/api/realty/staff', [
            'name' => 'Dina Docs', 'email' => 'Dina@Example.com', 'phone' => '0917 123 4567', 'password' => 'start-pass-1',
            'access' => 'sections', 'sections' => ['approvals'], 'must_change_password' => false,
        ])->assertCreated()->assertJson(['name' => 'Dina Docs', 'email' => 'dina@example.com', 'full_admin' => false, 'sections' => ['offers', 'approvals']]);

        $token = $this->signIn('dina@example.com', 'start-pass-1');
        $this->api('get', '/api/auth/me', $token)->assertJsonPath('access', ['offers', 'approvals']);
        $this->api('get', '/api/realty/offers', $token)->assertOk();
        $this->api('get', '/api/realty/projects', $token)->assertOk();
        $this->api('post', '/api/realty/projects', $token, ['name' => 'New'])->assertForbidden();
        $this->api('get', '/api/realty/agents', $token)->assertForbidden();
        $this->api('get', '/api/realty/requirements', $token)->assertForbidden();
        $this->api('get', '/api/realty/assistant/chats', $token)->assertForbidden();
        $this->api('get', '/api/realty/staff', $token)->assertForbidden();
        $this->assertNotNull(User::where('email', 'dina@example.com')->value('last_login_at'));
    }

    public function test_a_new_account_chooses_its_own_password_first_by_default(): void
    {
        $this->asOwner('post', '/api/realty/staff', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'start-pass-1', 'access' => 'full'])->assertCreated()->assertJsonPath('must_change_password', true);

        $token = $this->signIn('new@example.com', 'start-pass-1');
        $this->api('get', '/api/realty/overview', $token)->assertForbidden()->assertJsonPath('code', 'password_change_required');
    }

    public function test_the_admin_changes_access_resets_the_password_turns_off_and_deletes(): void
    {
        $staff = $this->staff('Sam Sales', ['offers']);
        $theirs = $staff->createToken('web')->plainTextToken;

        $this->asOwner('post', "/api/realty/staff/{$staff->id}/access", ['access' => 'sections', 'sections' => ['offers', 'projects']])->assertOk()->assertJsonPath('sections', ['offers', 'projects']);
        $this->asOwner('post', "/api/realty/staff/{$staff->id}/access", ['access' => 'sections', 'sections' => ['nonsense']])->assertJsonValidationErrors('sections.0');

        $this->asOwner('post', "/api/realty/staff/{$staff->id}/password", ['password' => 'fresh-pass-2', 'must_change_password' => false])->assertOk();
        $this->assertTrue(Hash::check('fresh-pass-2', $staff->fresh()->password));
        $this->api('get', '/api/auth/me', $theirs)->assertUnauthorized();

        $this->asOwner('post', "/api/realty/staff/{$staff->id}/status", ['status' => User::STATUS_DISABLED])->assertOk()->assertJsonPath('status', 'disabled');
        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'fresh-pass-2', 'realty' => 'johndorf'])->assertJsonValidationErrors('email');
        $this->asOwner('post', "/api/realty/staff/{$staff->id}/status", ['status' => User::STATUS_ACTIVE])->assertOk();
        $this->signIn($staff->email, 'fresh-pass-2');

        $this->asOwner('delete', "/api/realty/staff/{$staff->id}")->assertNoContent();
        $this->assertNull($staff->fresh());
    }

    public function test_someone_who_sent_offers_is_turned_off_not_deleted(): void
    {
        $staff = $this->staff('Olive Offers', ['offers']);
        $project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Plumera Mactan', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'name' => 'Unit 1', 'price' => 1000000, 'status' => 'available']);
        Offer::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'unit_id' => $unit->id, 'agent_id' => $staff->id, 'code' => Offer::newCode(), 'buyer_name' => 'Buyer', 'purchase_date' => now()->toDateString(), 'price' => 1000000, 'schedule' => [], 'status' => 'active']);

        $this->asOwner('delete', "/api/realty/staff/{$staff->id}")->assertConflict();
        $this->assertNotNull($staff->fresh());
    }

    public function test_there_is_always_a_full_admin_and_nobody_changes_their_own_account_here(): void
    {
        $other = $this->staff('Second Admin', null);

        $this->asOwner('post', "/api/realty/staff/{$this->owner->id}/access", ['access' => 'sections', 'sections' => ['offers']])->assertStatus(422);
        $this->asOwner('post', "/api/realty/staff/{$other->id}/status", ['status' => User::STATUS_DISABLED])->assertOk();

        // With the second admin off, the owner is the only full admin left: they can't be demoted or turned off by the other.
        $other->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->asOwner('post', "/api/realty/staff/{$other->id}/access", ['access' => 'sections', 'sections' => ['offers']])->assertOk();
        $this->api('post', "/api/realty/staff/{$this->owner->id}/status", $this->owner->createToken('x')->plainTextToken, ['status' => User::STATUS_DISABLED])->assertStatus(422);
        $this->assertSame(User::STATUS_ACTIVE, $this->owner->fresh()->status);
    }

    public function test_staff_from_another_realty_or_agents_are_out_of_reach(): void
    {
        $elsewhere = Realty::create(['name' => 'Other', 'slug' => 'other', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $stranger = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $elsewhere->id]);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id]);

        $this->asOwner('post', "/api/realty/staff/{$stranger->id}/status", ['status' => User::STATUS_DISABLED])->assertNotFound();
        $this->asOwner('delete', "/api/realty/staff/{$agent->id}")->assertNotFound();
        $this->api('get', '/api/realty/staff', $agent->createToken('web')->plainTextToken)->assertForbidden();
        $this->assertSame([], collect($this->asOwner('get', '/api/realty/staff')->json('staff'))->where('id', $stranger->id)->all());
    }

    public function test_an_accredited_realty_offers_only_its_selling_sections(): void
    {
        $broker = Realty::create(['name' => 'ABC Realty', 'slug' => 'abc-realty', 'kind' => Realty::KIND_BROKER, 'developer_id' => $this->realty->id, 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $broker->id]);

        $this->api('get', '/api/realty/staff', $admin->createToken('web')->plainTextToken)->assertOk()->assertJsonPath('sections', ['offers', 'agents']);
        $this->api('post', '/api/realty/staff', $admin->createToken('web')->plainTextToken, ['name' => 'X', 'email' => 'x@example.com', 'password' => 'start-pass-1', 'access' => 'sections', 'sections' => ['projects']])->assertJsonValidationErrors('sections.0');
    }

    public function test_the_ai_only_tells_staff_what_their_account_includes(): void
    {
        $noOffers = $this->staff('Pia Projects', ['projects', 'ai']);

        $this->assertArrayHasKey('error', (new RealtyTools($noOffers))->call('list_offers', []));
        $this->assertArrayHasKey('error', (new RealtyTools($noOffers))->call('list_agents', []));
        $this->assertArrayNotHasKey('error', (new RealtyTools($noOffers))->call('list_projects', []));
        $this->assertSame(0, (new RealtyTools($noOffers))->attention()['total_things_to_do']);
    }

    /** @param  array<int, string>|null  $sections */
    private function staff(string $name, ?array $sections): User
    {
        return User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'name' => $name, 'permissions' => $sections]);
    }

    private function signIn(string $email, string $password): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password, 'realty' => 'johndorf'])->assertOk()->json('token');
    }

    private function asOwner(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->api($method, $uri, $this->owner->createToken('web')->plainTextToken, $data);
    }

    /** Each call is a fresh request with its own token, as from the Next.js server. */
    private function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}
