<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The super admin's password is typed in when seeding: never read from .env or the code. */
class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ASK = 'Password for '.SuperAdminSeeder::EMAIL;

    public function test_it_creates_the_super_admin_with_the_typed_password(): void
    {
        $this->artisan('db:seed', ['--class' => SuperAdminSeeder::class])
            ->expectsQuestion(self::ASK, 'typed-in-secret')
            ->expectsQuestion('Type it again', 'typed-in-secret')
            ->assertSuccessful();

        $user = User::where('email', SuperAdminSeeder::EMAIL)->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue(Hash::check('typed-in-secret', $user->password));
    }

    public function test_mismatched_or_short_passwords_change_nothing(): void
    {
        $this->artisan('db:seed', ['--class' => SuperAdminSeeder::class])
            ->expectsQuestion(self::ASK, 'typed-in-secret')
            ->expectsQuestion('Type it again', 'something-else')
            ->assertSuccessful();
        $this->artisan('db:seed', ['--class' => SuperAdminSeeder::class])
            ->expectsQuestion(self::ASK, 'short')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => SuperAdminSeeder::EMAIL]);
    }

    public function test_running_it_again_with_an_empty_password_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['email' => SuperAdminSeeder::EMAIL, 'role' => User::ROLE_ADMIN, 'password' => 'current-password']);

        $this->artisan('db:seed', ['--class' => SuperAdminSeeder::class])
            ->expectsQuestion(self::ASK.' (leave empty to keep the current one)', '')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('current-password', $user->password));
    }
}
