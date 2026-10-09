<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An offer is open for as long as the agent chose ("valid for 3 days"). After `expires_at` the buyer's link,
 * sign-in and answers stop working until the agent extends it. Offers made before this have none and stay open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
