<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johndorf is the developer; outside realties are accredited under it.
 *
 *  - realties.kind: "developer" owns projects and units (every realty so far, Johndorf
 *    included, stays one); "broker" is an accredited realty that sells a developer's units
 *    through its own agents, with developer_id pointing at that developer.
 *  - offers.broker_realty_id: the broker firm that made the sale. The offer itself stays
 *    on the developer's side (realty_id), so approvals, buyer requirements and unit
 *    status keep working where the inventory lives.
 *  - users.must_change_password: set when an accepted realty gets its temporary password.
 *  - realty_accreditations / accreditation_documents: the invite, the form a realty fills
 *    in, its files, and the developer's decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realties', function (Blueprint $table) {
            $table->string('kind', 20)->default('developer')->after('slug'); // developer | broker
            $table->foreignId('developer_id')->nullable()->after('kind')->constrained('realties')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('status');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->foreignId('broker_realty_id')->nullable()->after('realty_id')->constrained('realties')->nullOnDelete();
            $table->index(['broker_realty_id', 'agent_id']);
        });

        Schema::create('realty_accreditations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 190);                 // where the invite went
            $table->string('token_hash', 64)->unique();   // sha256 of the link's token
            $table->timestamp('expires_at');
            $table->string('status', 20)->default('invited'); // invited | submitted | approved | rejected
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();

            // Accreditation details
            $table->string('business_type', 20)->nullable();  // corporation | sole_proprietor
            $table->string('firm_name', 150)->nullable();
            $table->string('residential_address', 255)->nullable();
            $table->text('tin_company')->nullable();       // encrypted
            $table->text('tin_personal')->nullable();      // encrypted
            $table->string('prc_number', 60)->nullable();
            $table->date('prc_valid_until')->nullable();
            $table->string('hlurb_number', 60)->nullable();
            $table->date('hlurb_issued_at')->nullable();

            // Personal and contact details
            $table->string('representative_name', 120)->nullable();
            $table->string('place_of_birth', 150)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('citizenship', 80)->nullable();
            $table->string('gender', 10)->nullable();         // male | female
            $table->string('civil_status', 20)->nullable();
            $table->string('landline', 40)->nullable();
            $table->string('mobile', 11)->nullable();
            $table->string('login_email', 190)->nullable();   // becomes the username once accepted
            $table->string('facebook', 190)->nullable();
            $table->unsignedSmallInteger('years_in_real_estate')->nullable();
            $table->unsignedSmallInteger('years_firm_operating')->nullable();
            $table->unsignedSmallInteger('salespersons')->nullable();

            // The decision
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('realty_id')->nullable()->constrained('realties')->nullOnDelete(); // the broker made on accept
            $table->timestamps();

            $table->index(['developer_id', 'status']);
        });

        Schema::create('accreditation_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accreditation_id')->constrained('realty_accreditations')->cascadeOnDelete();
            $table->string('kind', 40);          // board_resolution | sec_registration | partnership | dti_registration | gsis
            $table->string('path');              // on the private documents disk
            $table->string('original_name', 250);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->index(['accreditation_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_documents');
        Schema::dropIfExists('realty_accreditations');

        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex(['broker_realty_id', 'agent_id']);
            $table->dropConstrainedForeignId('broker_realty_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        Schema::table('realties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('developer_id');
            $table->dropColumn('kind');
        });
    }
};
