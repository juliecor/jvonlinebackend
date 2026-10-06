<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A realty's project can have a public page (jvconline.ph/projects/<slug>):
     * hero photos, site plans, amenities, the house/unit models with their
     * renders and specs, and construction updates month by month.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->after('name');
            $table->string('region', 60)->nullable()->after('location');   // "Cebu", "Cagayan de Oro", …
            $table->string('stage', 40)->nullable()->after('region');       // "Ongoing", "Completed", "Pre-selling"
            $table->boolean('is_public')->default(false)->after('status');
            $table->json('hero_paths')->nullable()->after('cover_path');
            $table->json('site_plan_paths')->nullable()->after('hero_paths');
            $table->json('amenities')->nullable()->after('site_plan_paths');
            $table->string('official_url')->nullable()->after('amenities');
            $table->unique(['realty_id', 'slug']);
        });

        Schema::create('unit_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');                       // "Two-Storey Townhouse"
            $table->json('specs')->nullable();            // {usable_floor_area, typical_floor_area, bedrooms, baths, floors, parking}
            $table->json('image_paths')->nullable();      // renders / floor plans
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->char('month', 7);                     // "2025-06"
            $table->string('label')->nullable();          // "June 2025" — derived when empty
            $table->json('photo_paths')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_updates');
        Schema::dropIfExists('unit_types');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['realty_id', 'slug']);
            $table->dropColumn(['slug', 'region', 'stage', 'is_public', 'hero_paths', 'site_plan_paths', 'amenities', 'official_url']);
        });
    }
};
