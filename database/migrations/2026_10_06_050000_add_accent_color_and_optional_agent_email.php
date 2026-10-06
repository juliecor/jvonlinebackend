<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A realty's brand colour tints its dashboard and login. Agent invites are
     * now links the realty passes on itself, so the email may be filled in by
     * the agent when they join.
     */
    public function up(): void
    {
        Schema::table('realties', function (Blueprint $table) {
            $table->string('accent_color', 7)->nullable()->after('logo_path'); // #rrggbb
        });
        Schema::table('agent_invitations', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('realties', fn (Blueprint $table) => $table->dropColumn('accent_color'));
        Schema::table('agent_invitations', fn (Blueprint $table) => $table->string('email')->nullable(false)->change());
    }
};
