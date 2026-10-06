<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two kinds of unit notes: `notes` stays internal (staff and agents only —
     * e.g. where a price came from), `buyer_notes` is printed on the sales offer
     * (e.g. "Corner lot, facing the park").
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->text('buyer_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('buyer_notes'));
    }
};
