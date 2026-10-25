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
        Schema::create('commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained();
            $table->foreignUuid('payment_id')->unique()->constrained();
            $table->foreignUuid('subscription_id')->nullable()->constrained();
            $table->string('currency', 3);
            $table->unsignedBigInteger('gross_amount'); // the payment amount, minor units
            $table->unsignedBigInteger('refunded_amount')->default(0); // how much of the gross was refunded
            $table->decimal('rate_percent', 5, 2); // snapshot of the rate the subscription was sold at
            $table->unsignedBigInteger('commission_amount'); // the platform fee on the gross
            $table->unsignedBigInteger('commission_refunded')->default(0); // the part of the fee given back with refunds
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
