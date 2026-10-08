<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a unit is reserved or sold to: the offer (and so its buyer and agent),
 * who marked it, and when. Set from the sales offer; cleared when the unit
 * is available again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('status_offer_id')->nullable()->after('status')->constrained('offers')->nullOnDelete();
            $table->foreignId('status_by_id')->nullable()->after('status_offer_id')->constrained('users')->nullOnDelete();
            $table->timestamp('status_at')->nullable()->after('status_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_offer_id');
            $table->dropConstrainedForeignId('status_by_id');
            $table->dropColumn('status_at');
        });
    }
};
