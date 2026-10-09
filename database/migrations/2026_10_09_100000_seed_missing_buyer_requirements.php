<?php

use App\Models\RequirementType;
use Illuminate\Database\Migrations\Migration;

/**
 * Realties set up after the buyer requirements arrived (e.g. Johndorf, made
 * by the seeder on a fresh database) got none, so their buyers never saw a
 * Requirements tab. Each realty without any gets the standard list; one
 * that already has its own list is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        RequirementType::seedMissing();
    }

    public function down(): void
    {
        // The list may have been edited since; it stays.
    }
};
