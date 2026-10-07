<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Staff can note the agent's mobile number when making the invite; the join form starts with it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_invitations', function (Blueprint $table) {
            $table->string('phone', 11)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('agent_invitations', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
