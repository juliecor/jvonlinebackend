<?php

namespace Database\Seeders;

use App\Models\Realty;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The first admin (from ADMIN_* in .env, so no password sits in the repo)
     * and Johndorf, realty #1 — it already has its page at /johndorf.
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

        Realty::updateOrCreate(
            ['slug' => 'johndorf'],
            ['name' => 'Johndorf Ventures Corporation', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(), 'logo_path' => '/johndorf/logo.png', 'accent_color' => '#b4241c'],
        );
        $this->command->info('Realty: Johndorf Ventures Corporation (/johndorf)');
    }
}
