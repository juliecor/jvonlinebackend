<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A private offer: the agent sets a username and password, and the buyer types
 * them to open the link. The password is stored encrypted (not hashed) so the
 * agent can see it again when the buyer forgets. Offers made before this stay open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('access_username', 60)->nullable()->after('buyer_phone');
            $table->text('access_password')->nullable()->after('access_username');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['access_username', 'access_password']);
        });
    }
};
