<?php

namespace Database\Seeders;

use App\Models\Realty;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The first admin (from ADMIN_* in .env, so no password sits in the repo),
     * Johndorf, realty #1 — it already has its page at /johndorf — and its
     * staff login (from JOHNDORF_ADMIN_* in .env), and the owner's super admin
     * (from SUPERADMIN_* in .env), who can view any realty's dashboard as any role.
     * Safe to run again: it updates rather than duplicates.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        if (! $email || ! $password) {
            $this->command->error('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env first.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => env('ADMIN_NAME', 'jvconline admin'), 'password' => $password, 'role' => User::ROLE_ADMIN, 'realty_id' => null],
        );
        $this->command->info("Admin: {$email}");

        $this->call(SuperAdminSeeder::class);

        $johndorf = Realty::updateOrCreate(
            ['slug' => 'johndorf'],
            ['name' => 'Johndorf Ventures Corporation', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(), 'logo_path' => '/johndorf/logo.png', 'accent_color' => '#b4241c'],
        );
        $this->command->info('Realty: Johndorf Ventures Corporation (/johndorf)');

        // Johndorf's own staff login (signs in at /johndorf/login, not /admin/login).
        $johndorfEmail = env('JOHNDORF_ADMIN_EMAIL');
        $johndorfPassword = env('JOHNDORF_ADMIN_PASSWORD');
        if ($johndorfEmail && $johndorfPassword) {
            User::updateOrCreate(
                ['email' => $johndorfEmail],
                ['name' => env('JOHNDORF_ADMIN_NAME', 'Johndorf Admin'), 'password' => $johndorfPassword, 'role' => User::ROLE_REALTY, 'realty_id' => $johndorf->id],
            );
            $this->command->info("Johndorf staff: {$johndorfEmail}");
        } else {
            $this->command->warn('Skipped the Johndorf staff login: set JOHNDORF_ADMIN_EMAIL and JOHNDORF_ADMIN_PASSWORD in .env.');
        }

        $this->call(JohndorfProjectsSeeder::class);
    }
}
