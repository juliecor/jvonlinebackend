<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a realty sells and how it is offered:
     * projects → units (what is for sale) and payment plans (how it is paid),
     * offers = one unit + one plan prepared by an agent for one buyer, reachable by a public code.
     * Everything carries realty_id so a query can always be scoped in one place.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->text('fee_notes')->nullable(); // e.g. "Transfer fees of 4% are paid by the buyer" — shown on every offer
            $table->date('completion_date')->nullable(); // for "on completion" milestones
            $table->string('status')->default('active'); // active | archived
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name'); // "Unit 415", "Lot 12, Block 3"
            $table->string('unit_type')->nullable(); // "1 Bedroom", "Townhouse", "Residential lot"
            $table->string('category')->default('Residential'); // Residential | Commercial
            $table->string('floor')->nullable(); // "4th floor", "Phase 2"
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->decimal('price', 14, 2);
            $table->string('status')->default('available'); // available | reserved | sold
            $table->string('floor_plan_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name'); // "10/10/10/70", "Spot cash"
            // [{label, percent, days|null}] — days from purchase date; null = on completion
            $table->json('milestones');
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('payment_plan_id')->nullable()->constrained('payment_plans')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 16)->unique(); // in the buyer's link
            $table->string('buyer_name');
            $table->string('buyer_email')->nullable();
            $table->date('purchase_date');
            $table->decimal('price', 14, 2); // snapshot: the unit's price when offered
            $table->json('schedule'); // snapshot: [{label, percent, date|null, amount}]
            $table->text('fee_notes')->nullable(); // snapshot
            $table->string('status')->default('active'); // active | void
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
        Schema::dropIfExists('payment_plans');
        Schema::dropIfExists('units');
        Schema::dropIfExists('projects');
    }
};
