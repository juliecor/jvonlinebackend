<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A realty is one company on the platform (Johndorf is the first).
     * Every realty-scoped table hangs off realties.id as realty_id.
     */
    public function up(): void
    {
        Schema::create('realties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // jvconline.ph/<slug>
            $table->string('email')->nullable(); // where the invite went / main contact
            $table->string('status')->default('invited'); // invited | active
            $table->string('logo_path')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realties');
    }
};
