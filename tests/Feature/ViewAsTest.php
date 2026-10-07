<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Realty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** A super admin switches into a realty's dashboard as its admin or an agent, and back, without their account changing. */
class ViewAsTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $superAdmin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Montierra', 'status' => 'active']);
        $this->superAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->superAdmin->forceFill(['is_superadmin' => true])->save();
    }

    public function test_super_admin_previews_a_realty_as_its_admin(): void
    {
        $token = $this->switchTo($this->adminToken(), 'realty', 'johndorf')->assertOk()->json('token');

        $this->api('get', '/api/auth/me', $token)
            ->assertOk()
            ->assertJsonPath('role', User::ROLE_REALTY)
            ->assertJsonPath('realty.slug', 'johndorf')
            ->assertJsonPath('is_superadmin', true);
        $this->api('get', '/api/realty/overview', $token)->assertOk();
        $this->api('post', "/api/realty/projects/{$this->project->id}/status", $token, ['stage' => 'Ongoing'])->assertOk();

        $this->superAdmin->refresh();
        $this->assertSame(User::ROLE_ADMIN, $this->superAdmin->role);
        $this->assertNull($this->superAdmin->realty_id);
    }

    public function test_as_an_agent_the_staff_pages_are_closed(): void
    {
        $token = $this->switchTo($this->adminToken(), 'agent', 'johndorf')->assertOk()->json('token');

        $this->api('get', '/api/auth/me', $token)->assertJsonPath('role', User::ROLE_AGENT);
        $this->api('get', '/api/realty/offers', $token)->assertOk();
        $this->api('post', "/api/realty/projects/{$this->project->id}/status", $token, ['stage' => 'Ongoing'])->assertForbidden();
        $this->api('get', '/api/admin/stats', $token)->assertForbidden();
    }

    public function test_switching_again_or_back_to_super_admin_ends_the_preview(): void
    {
        $asAdmin = $this->switchTo($this->adminToken(), 'realty', 'johndorf')->json('token');
        $asAgent = $this->switchTo($asAdmin, 'agent', 'johndorf')->assertOk()->json('token');
        $this->api('get', '/api/auth/me', $asAdmin)->assertUnauthorized();

        $platform = $this->switchTo($asAgent, 'admin')->assertOk()->assertJsonPath('user.role', User::ROLE_ADMIN)->json('token');
        $this->api('get', '/api/auth/me', $asAgent)->assertUnauthorized();
        $this->api('get', '/api/admin/stats', $platform)->assertOk();
    }

    public function test_only_super_admins_can_switch(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id]);

        foreach ([$admin, $staff] as $user) {
            $token = $user->createToken('web')->plainTextToken;
            $this->switchTo($token, 'realty', 'johndorf')->assertForbidden();
            $this->api('get', '/api/auth/view-as', $token)->assertForbidden();
        }
    }

    public function test_a_preview_token_is_useless_once_the_account_is_no_longer_super_admin(): void
    {
        $token = $this->switchTo($this->adminToken(), 'realty', 'johndorf')->json('token');
        $this->superAdmin->forceFill(['is_superadmin' => false])->save();

        $this->api('get', '/api/realty/overview', $token)->assertForbidden();
    }

    public function test_options_list_active_realties_only(): void
    {
        Realty::create(['name' => 'Invited Realty', 'slug' => 'invited', 'status' => Realty::STATUS_INVITED]);

        $this->api('get', '/api/auth/view-as', $this->adminToken())
            ->assertOk()
            ->assertJsonPath('realties', [['slug' => 'johndorf', 'name' => 'Johndorf Ventures Corporation']]);
        $this->switchTo($this->adminToken(), 'agent', 'invited')->assertUnprocessable()->assertJsonValidationErrors('realty');
    }

    public function test_super_admin_signs_in_at_a_realty_login_as_its_admin(): void
    {
        $this->superAdmin->update(['password' => 'boss-password']);

        $token = $this->postJson('/api/auth/login', ['email' => $this->superAdmin->email, 'password' => 'boss-password', 'realty' => 'johndorf'])
            ->assertOk()
            ->assertJsonPath('realty', 'johndorf')
            ->json('token');

        $this->api('get', '/api/auth/me', $token)->assertJsonPath('role', User::ROLE_REALTY)->assertJsonPath('realty.slug', 'johndorf');
        $this->api('get', '/api/realty/overview', $token)->assertOk();
        $this->assertSame(User::ROLE_ADMIN, $this->superAdmin->fresh()->role);
    }

    public function test_realty_logins_still_turn_away_other_accounts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => 'admin-password']);
        Realty::create(['name' => 'Invited Realty', 'slug' => 'invited', 'status' => Realty::STATUS_INVITED]);
        $this->superAdmin->update(['password' => 'boss-password']);

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'admin-password', 'realty' => 'johndorf'])
            ->assertJsonValidationErrors(['email' => 'This account does not belong to this realty.']);
        $this->postJson('/api/auth/login', ['email' => $this->superAdmin->email, 'password' => 'wrong', 'realty' => 'johndorf'])
            ->assertJsonValidationErrors(['email' => 'Wrong email or password.']);
        $this->postJson('/api/auth/login', ['email' => $this->superAdmin->email, 'password' => 'boss-password', 'realty' => 'invited'])
            ->assertJsonValidationErrors('email');
    }

    private function adminToken(): string
    {
        return $this->superAdmin->createToken('admin-web')->plainTextToken;
    }

    private function switchTo(string $token, string $role, ?string $realty = null): TestResponse
    {
        return $this->api('post', '/api/auth/view-as', $token, array_filter(['role' => $role, 'realty' => $realty]));
    }

    /** Each call is a fresh request with its own token, as from the Next.js server (no user cached between calls). */
    private function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}
