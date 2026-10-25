<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('receipt_sent_at')->nullable();
        });

        // The date each reminder was sent for, so a new trial end or billing period gets its own reminder.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_reminder_for')->nullable();
            $table->timestamp('renewal_reminder_for')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['trial_reminder_for', 'renewal_reminder_for']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('receipt_sent_at');
        });
    }
};
