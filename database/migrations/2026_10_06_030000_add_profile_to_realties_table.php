<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the realty tells us about itself on the registration form. */
    public function up(): void
    {
        Schema::table('realties', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('email');
            $table->string('phone', 40)->nullable()->after('contact_name');
            $table->string('address')->nullable()->after('phone');
            $table->text('about')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('realties', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'phone', 'address', 'about']);
        });
    }
};
