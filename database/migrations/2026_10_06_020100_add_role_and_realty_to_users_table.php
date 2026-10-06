<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One users table for all three levels: admin (no realty), realty staff, agent.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('agent')->after('password'); // admin | realty | agent
            $table->foreignId('realty_id')->nullable()->after('role')->constrained('realties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('realty_id');
            $table->dropColumn('role');
        });
    }
};
