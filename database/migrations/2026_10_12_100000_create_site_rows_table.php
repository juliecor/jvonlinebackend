<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clickable site development plan. An admin draws each row of lots on the project's plan image
 * (its four corners, as fractions of the image) and says which block, which lot number it starts at
 * and how many lots; every lot is a unit that knows its block, lot number, row and place in the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Which site plan image the map is drawn on (one of site_plan_paths).
            $table->string('site_map_path')->nullable()->after('site_plan_paths');
        });

        Schema::create('site_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('block', 20);
            $table->json('corners');
            $table->unsignedInteger('first_lot');
            $table->unsignedSmallInteger('lot_count');
            $table->timestamps();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->string('block', 20)->nullable()->after('name');
            $table->string('lot', 20)->nullable()->after('block');
            $table->foreignId('site_row_id')->nullable()->after('lot')->constrained('site_rows')->nullOnDelete();
            $table->unsignedSmallInteger('row_index')->nullable()->after('site_row_id');
            $table->decimal('lot_area_sqm', 10, 2)->nullable()->after('area_sqm');
            $table->json('tags')->nullable()->after('lot_area_sqm');
            $table->index(['project_id', 'block', 'lot']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'block', 'lot']);
            $table->dropConstrainedForeignId('site_row_id');
            $table->dropColumn(['block', 'lot', 'row_index', 'lot_area_sqm', 'tags']);
        });
        Schema::dropIfExists('site_rows');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('site_map_path');
        });
    }
};
