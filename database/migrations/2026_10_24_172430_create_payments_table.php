<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending, succeeded, failed, refunded, partially_refunded
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('amount_refunded')->default(0);
            $table->char('currency', 3);
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_charge_id')->nullable()->unique();
            $table->unsignedBigInteger('stripe_event_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index('subscription_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
