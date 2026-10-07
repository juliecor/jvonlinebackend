<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The owner's super admin (from SUPERADMIN_* in .env, so no password sits in
 * the repo): a platform admin who can also view any realty's dashboard as its
 * admin or as an agent. Run on its own with --class=SuperAdminSeeder.
 * Safe to run again: it updates rather than duplicates.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL');
        $password = env('SUPERADMIN_PASSWORD');
        if (! $email || ! $password) {
            $this->command->warn('Skipped the super admin: set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD in .env.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => env('SUPERADMIN_NAME', 'Super Admin'), 'password' => $password, 'role' => User::ROLE_ADMIN, 'realty_id' => null],
        )->forceFill(['is_superadmin' => true])->save();
        $this->command->info("Super admin: {$email}");
    }
}
