<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Stripe's invoice id as the payment event named it, so a payment that arrives before its invoice can be linked afterwards.
            $table->string('stripe_invoice_id')->nullable()->after('invoice_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['stripe_invoice_id']);
            $table->dropColumn('stripe_invoice_id');
        });
    }
};
