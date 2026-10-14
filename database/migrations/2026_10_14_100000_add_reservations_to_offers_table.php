<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reserving the home from the sales offer: the fee the agent set, how the buyer paid it (online through
 * PayMongo, or a bank deposit / GCash with a photo of the proof), and the developer's confirmation that
 * turns the unit reserved. The developer types where buyers pay (bank, GCash) once, on its realty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->decimal('reservation_fee', 12, 2)->nullable()->after('price');
            // null (not asked) → pending (proof sent / online started) → paid (online payment went through) → confirmed | declined
            $table->string('reservation_status', 20)->nullable()->after('reservation_fee');
            $table->string('reservation_method', 20)->nullable()->after('reservation_status'); // online | proof
            $table->string('reservation_proof_path')->nullable()->after('reservation_method');
            $table->string('reservation_reference', 120)->nullable()->after('reservation_proof_path'); // GCash/bank reference, or the PayMongo payment id
            $table->string('reservation_checkout_id', 80)->nullable()->after('reservation_reference'); // the PayMongo checkout session
            $table->text('reservation_note')->nullable()->after('reservation_checkout_id'); // the buyer's note, or the reason it was declined
            $table->timestamp('reservation_requested_at')->nullable()->after('reservation_note');
            $table->timestamp('reservation_paid_at')->nullable()->after('reservation_requested_at');
            $table->timestamp('reservation_confirmed_at')->nullable()->after('reservation_paid_at');
            $table->foreignId('reservation_confirmed_by')->nullable()->after('reservation_confirmed_at')->constrained('users')->nullOnDelete();
            $table->index('reservation_checkout_id');
        });
        Schema::table('realties', function (Blueprint $table) {
            $table->text('payment_instructions')->nullable()->after('about');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_confirmed_by');
            $table->dropIndex(['reservation_checkout_id']);
            $table->dropColumn(['reservation_fee', 'reservation_status', 'reservation_method', 'reservation_proof_path', 'reservation_reference', 'reservation_checkout_id', 'reservation_note', 'reservation_requested_at', 'reservation_paid_at', 'reservation_confirmed_at']);
        });
        Schema::table('realties', function (Blueprint $table) {
            $table->dropColumn('payment_instructions');
        });
    }
};
