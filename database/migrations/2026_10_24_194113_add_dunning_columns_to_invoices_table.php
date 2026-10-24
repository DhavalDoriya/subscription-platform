<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // retrying (soft decline, on the schedule), awaiting_customer (hard or 3DS), exhausted (schedule used up, in grace), recovered, resolved (marked by hand)
            $table->string('dunning_status')->nullable()->after('status');
            $table->timestamp('first_failed_at')->nullable()->after('dunning_status');
            $table->timestamp('next_retry_at')->nullable()->after('first_failed_at');

            $table->index(['dunning_status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['dunning_status', 'next_retry_at']);
            $table->dropColumn(['dunning_status', 'first_failed_at', 'next_retry_at']);
        });
    }
};
