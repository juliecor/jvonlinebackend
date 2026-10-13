<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Realties can send the accreditation form from the developer's website, without an invite:
 * where a form came from, and no link or expiry for the ones that never had an invite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realty_accreditations', function (Blueprint $table) {
            $table->string('source', 20)->default('invite')->after('invited_by'); // invite | website
            $table->string('token_hash', 64)->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Website forms get a link nobody knows and an expiry in the past, so the columns can be required again.
        DB::table('realty_accreditations')->whereNull('token_hash')->orderBy('id')->each(fn ($row) => DB::table('realty_accreditations')->where('id', $row->id)->update([
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => $row->expires_at ?? $row->created_at,
        ]));

        Schema::table('realty_accreditations', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->string('token_hash', 64)->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
