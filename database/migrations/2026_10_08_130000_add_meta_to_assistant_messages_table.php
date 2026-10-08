<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an AI answer showed besides its text: e.g. {"units": [12, 40]}, the
 * units it put under the answer as cards. Only ids are kept; the cards are
 * drawn from the live units, so a price or status is never out of date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
