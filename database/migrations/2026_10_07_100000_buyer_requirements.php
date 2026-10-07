<?php

use App\Models\Realty;
use App\Models\RequirementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer requirements: the realty's checklist (IDs, proof of income…), the
 * buyer's details and uploaded documents on each offer, and reminder tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('buyer_phone', 40)->nullable()->after('buyer_email');
            $table->json('buyer_details')->nullable()->after('fee_notes');
            $table->timestamp('details_submitted_at')->nullable()->after('buyer_details');
            $table->timestamp('offer_emailed_at')->nullable()->after('details_submitted_at');
            $table->timestamp('last_reminded_at')->nullable()->after('offer_emailed_at');
            $table->unsignedSmallInteger('reminders_sent')->default(0)->after('last_reminded_at');
        });

        Schema::create('requirement_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('help')->nullable();
            // Who must submit it: everyone, only if applicable, or tied to the buyer's details (see RequirementType::APPLIES).
            $table->string('applies', 40)->default('all');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['realty_id', 'active']);
        });

        Schema::create('offer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('requirement_type_id')->nullable()->constrained('requirement_types')->nullOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->string('note', 300)->nullable();          // why it was rejected, shown to the buyer
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['realty_id', 'status']);
        });

        // Realties that already exist get the starting checklist (new ones get it when they register).
        foreach (Realty::all() as $realty) {
            RequirementType::seedDefaults($realty);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_documents');
        Schema::dropIfExists('requirement_types');
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['buyer_phone', 'buyer_details', 'details_submitted_at', 'offer_emailed_at', 'last_reminded_at', 'reminders_sent']);
        });
    }
};
