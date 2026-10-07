<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent applications: an agent who fills in the join form gives a contact number
 * and a resume, and waits as "pending" until the realty's staff approve them.
 * Everyone already signed up stays "active".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('role'); // pending | active | rejected
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('resume_path')->nullable()->after('phone');
            $table->string('resume_name', 250)->nullable()->after('resume_path');
            $table->unsignedInteger('resume_size')->nullable()->after('resume_name');
            $table->foreignId('reviewed_by')->nullable()->after('realty_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index(['realty_id', 'role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['realty_id', 'role', 'status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'phone', 'resume_path', 'resume_name', 'resume_size', 'reviewed_at']);
        });
    }
};
