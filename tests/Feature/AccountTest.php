<?php

namespace Tests\Feature;

use App\Models\Realty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Everyone edits their own name, email, phone and password, and nothing else about their account. */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ana Agent', 'email' => 'ana@example.com']);
    }

    public function test_people_change_their_name_and_phone(): void
    {
        $token = $this->agent->createToken('web')->plainTextToken;

        $this->api('patch', '/api/account', $token, ['name' => '  Ana Santos ', 'email' => 'ana@example.com', 'phone' => '0917 123 4567'])
            ->assertOk()
            ->assertJson(['name' => 'Ana Santos', 'phone' => '0917 123 4567', 'realty' => 'Johndorf Ventures Corporation']);
        $this->api('patch', '/api/account', $token, ['name' => 'Ana Santos', 'email' => 'ana@example.com', 'phone' => 'call me'])->assertJsonValidationErrors('phone');

        $this->assertSame(['Ana Santos', '0917 123 4567', User::ROLE_AGENT], [$this->agent->fresh()->name, $this->agent->fresh()->phone, $this->agent->fresh()->role]);
    }

    public function test_a_new_sign_in_email_needs_the_current_password_and_must_be_free(): void
    {
        $token = $this->agent->createToken('web')->plainTextToken;
        User::factory()->create(['email' => 'taken@example.com']);
        $change = fn (array $extra) => $this->api('patch', '/api/account', $token, ['name' => 'Ana Agent', 'email' => 'Ana.New@Example.com'] + $extra);

        $change([])->assertJsonValidationErrors('current_password');
        $change(['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->api('patch', '/api/account', $token, ['name' => 'Ana Agent', 'email' => 'taken@example.com', 'current_password' => 'password'])->assertJsonValidationErrors('email');
        $change(['current_password' => 'password'])->assertOk()->assertJsonPath('email', 'ana.new@example.com');

        $this->assertSame('ana.new@example.com', $this->agent->fresh()->email);
    }

    public function test_a_new_password_needs_the_current_one_and_signs_out_other_devices(): void
    {
        $here = $this->agent->createToken('web')->plainTextToken;
        $phone = $this->agent->createToken('phone')->plainTextToken;

        $this->api('post', '/api/account/password', $here, ['current_password' => 'nope', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertJsonValidationErrors('current_password');
        $this->api('post', '/api/account/password', $here, ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'other'])->assertJsonValidationErrors('password');
        $this->api('post', '/api/account/password', $here, ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertOk();

        $this->assertTrue(Hash::check('new-password-1', $this->agent->fresh()->password));
        $this->api('get', '/api/account', $here)->assertOk();
        $this->api('get', '/api/account', $phone)->assertUnauthorized();
    }

    public function test_a_super_admin_previewing_a_realty_keeps_their_real_role(): void
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'The Boss']);
        $boss->forceFill(['is_superadmin' => true])->save();
        $preview = $this->api('post', '/api/auth/view-as', $boss->createToken('admin-web')->plainTextToken, ['role' => User::ROLE_AGENT, 'realty' => 'johndorf'])->json('token');

        $this->api('patch', '/api/account', $preview, ['name' => 'Anthony Leuterio', 'email' => $boss->email])->assertOk()->assertJsonPath('name', 'Anthony Leuterio');

        $saved = $boss->fresh();
        $this->assertSame(['Anthony Leuterio', User::ROLE_ADMIN, null, true], [$saved->name, $saved->role, $saved->realty_id, $saved->isSuperAdmin()]);
    }

    /** Each call is a fresh request with its own token, as from the Next.js server. */
    private function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}
