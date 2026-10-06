<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An invite a realty sent to one of its agents. Same token scheme as realty_invitations. */
    public function up(): void
    {
        Schema::create('agent_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['realty_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_invitations');
    }
};
