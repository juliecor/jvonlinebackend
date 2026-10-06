<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A unit may be listed before its price is set ("price on request"); only priced units can be offered. */
    public function up(): void
    {
        Schema::table('units', fn (Blueprint $table) => $table->decimal('price', 14, 2)->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('units', fn (Blueprint $table) => $table->decimal('price', 14, 2)->nullable(false)->change());
    }
};
