<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A realty's staff accounts: permissions lists the dashboard sections a staff
 * member may use (null = everything, a full admin; agents don't use it), and
 * last_login_at shows the admin when each person last signed in. A staff
 * account can also be turned off (status "disabled").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'last_login_at']);
        });
    }
};
