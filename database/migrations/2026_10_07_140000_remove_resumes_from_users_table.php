<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Agents no longer send a resume when they apply: drop the resume columns and the
 * files already uploaded (they sat under resumes/ on the private documents disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Storage::disk(config('filesystems.documents'))->deleteDirectory('resumes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['resume_path', 'resume_name', 'resume_size']);
        });
    }

    /** Brings the columns back empty; deleted files can't be restored. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('resume_path')->nullable()->after('phone');
            $table->string('resume_name', 250)->nullable()->after('resume_path');
            $table->unsignedInteger('resume_size')->nullable()->after('resume_name');
        });
    }
};
