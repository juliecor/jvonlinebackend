<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The broker's accreditation agreement and how long an accreditation lasts.
 * A developer keeps its agreement details (who signs for it, its office, the
 * commission scheme, days allowed for the signed copy); each accreditation
 * keeps what the realty agreed to online (and who, when), the period the
 * developer set when accepting it (or an early end), the signed and notarized
 * copy's status, and which reminder emails went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realties', function (Blueprint $table) {
            $table->json('agreement_settings')->nullable()->after('accent_color');
        });

        Schema::table('realty_accreditations', function (Blueprint $table) {
            $table->string('agreement_version', 20)->nullable()->after('salespersons');
            $table->json('agreement')->nullable()->after('agreement_version');
            $table->string('agreed_name', 120)->nullable()->after('agreement');
            $table->timestamp('agreed_at')->nullable()->after('agreed_name');
            $table->string('agreed_ip', 45)->nullable()->after('agreed_at');
            $table->date('accredited_from')->nullable()->after('realty_id');
            $table->date('accredited_until')->nullable()->after('accredited_from');
            $table->timestamp('ended_at')->nullable()->after('accredited_until');
            $table->string('end_note', 300)->nullable()->after('ended_at');
            $table->date('signed_copy_due')->nullable()->after('end_note');
            $table->string('signed_copy_status', 20)->nullable()->after('signed_copy_due'); // missing | submitted | verified | returned
            $table->string('signed_copy_note', 300)->nullable()->after('signed_copy_status');
            $table->foreignId('signed_copy_checked_by')->nullable()->after('signed_copy_note')->constrained('users')->nullOnDelete();
            $table->timestamp('signed_copy_checked_at')->nullable()->after('signed_copy_checked_by');
            $table->timestamp('expiry_reminded_at')->nullable()->after('signed_copy_checked_at');
            $table->timestamp('ended_notified_at')->nullable()->after('expiry_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('realty_accreditations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_copy_checked_by');
            $table->dropColumn(['agreement_version', 'agreement', 'agreed_name', 'agreed_at', 'agreed_ip', 'accredited_from', 'accredited_until', 'ended_at', 'end_note', 'signed_copy_due', 'signed_copy_status', 'signed_copy_note', 'signed_copy_checked_at', 'expiry_reminded_at', 'ended_notified_at']);
        });
        Schema::table('realties', function (Blueprint $table) {
            $table->dropColumn('agreement_settings');
        });
    }
};
