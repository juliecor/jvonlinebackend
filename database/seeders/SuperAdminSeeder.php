<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The owner's super admin: a platform admin who can also view any realty's
 * dashboard as its admin or as an agent. The password is typed in when the
 * seeder runs, so it is never stored in .env or in this public repo.
 * Run on its own with --class=SuperAdminSeeder. Safe to run again.
 */
class SuperAdminSeeder extends Seeder
{
    public const EMAIL = 'mindworth@gmail.com';

    public function run(): void
    {
        $user = User::where('email', self::EMAIL)->first();
        $password = (string) $this->command->secret('Password for '.self::EMAIL.($user ? ' (leave empty to keep the current one)' : ''));

        if ($password === '' && ! $user) {
            $this->command->warn('Skipped the super admin: run this seeder in a terminal and type a password.');

            return;
        }
        if ($password !== '') {
            if (strlen($password) < 8) {
                $this->command->error('The password needs at least 8 characters. Nothing was changed.');

                return;
            }
            if ($password !== (string) $this->command->secret('Type it again')) {
                $this->command->error("The two passwords don't match. Nothing was changed.");

                return;
            }
        }

        $user ??= new User(['email' => self::EMAIL, 'name' => 'Super Admin']);
        $user->forceFill(['role' => User::ROLE_ADMIN, 'realty_id' => null, 'is_superadmin' => true] + ($password !== '' ? ['password' => $password] : []))->save();
        $this->command->info('Super admin: '.self::EMAIL.($password === '' ? ' (password unchanged)' : ''));
    }
}
