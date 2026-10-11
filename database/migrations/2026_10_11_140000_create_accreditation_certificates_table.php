<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificates of accreditation: one for each period a realty is fully accredited (its signed agreement
 * checked), kept as issued. A number to print (JVC-2026-0001) and a random code for the public check page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_accreditation_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->unique();
            $table->string('code', 20)->unique();
            $table->string('firm_name');
            $table->string('representative_name')->nullable();
            $table->string('developer_name');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->string('signer_name')->nullable();
            $table->string('signer_title')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_certificates');
    }
};
