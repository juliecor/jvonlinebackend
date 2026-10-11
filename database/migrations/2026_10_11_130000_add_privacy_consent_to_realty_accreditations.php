<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the realty agreed to the data privacy notice on its accreditation form (required to send it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realty_accreditations', function (Blueprint $table) {
            $table->timestamp('privacy_accepted_at')->nullable()->after('agreed_ip');
        });
    }

    public function down(): void
    {
        Schema::table('realty_accreditations', function (Blueprint $table) {
            $table->dropColumn('privacy_accepted_at');
        });
    }
};
