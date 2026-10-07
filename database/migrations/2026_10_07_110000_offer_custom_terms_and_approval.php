<?php

use App\Models\PaymentPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom terms: an agent's own schedule for one offer, which the realty's
 * admin approves before the buyer can open it. Official plans don't need it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->json('custom_milestones')->nullable()->after('schedule');
            $table->string('approval_status', 20)->nullable()->after('custom_milestones'); // null (official plan) | pending | approved | rejected
            $table->string('approval_reason', 500)->nullable()->after('approval_status');   // the agent's note to the approver
            $table->string('approval_note', 500)->nullable()->after('approval_reason');     // the approver's note back
            $table->foreignId('approved_by')->nullable()->after('approval_note')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->boolean('email_on_approval')->default(false)->after('approved_at');
            $table->index(['realty_id', 'approval_status']);
        });

        // Montierra's plan: Johndorf's Buyer's Guide says equity payments start 30 days after reservation —
        // so the 24-month equity is now 24 monthly payments from day 30, not one lump at month 24.
        PaymentPlan::where('name', 'Reservation, 24-month equity, balance via Pag-IBIG or bank')->get()->each(function (PaymentPlan $plan) {
            $plan->update(['milestones' => array_map(
                fn ($m) => str_starts_with($m['label'], 'Equity') ? ['label' => 'Equity, 24 monthly payments', 'percent' => $m['percent'], 'days' => 30, 'months' => 24] : $m + ['months' => null],
                $plan->milestones,
            )]);
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex(['realty_id', 'approval_status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['custom_milestones', 'approval_status', 'approval_reason', 'approval_note', 'approved_at', 'email_on_approval']);
        });
    }
};
