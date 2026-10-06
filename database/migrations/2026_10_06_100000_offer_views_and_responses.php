<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads from a sales offer: when the buyer first/last opened it, and what
     * they answered (interested, a question, not for them) with how to reach them.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('first_viewed_at')->nullable()->after('views');
            $table->timestamp('last_viewed_at')->nullable()->after('first_viewed_at');
        });

        Schema::create('offer_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('realty_id')->constrained('realties')->cascadeOnDelete();
            $table->string('kind', 20);                 // interested | question | not_interested
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_via', 20)->nullable(); // call | viber | whatsapp | sms | email
            $table->text('message')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('seen_at')->nullable();   // first opened by the realty's people
            $table->timestamps();
            $table->index(['realty_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_responses');
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn(['first_viewed_at', 'last_viewed_at']));
    }
};
