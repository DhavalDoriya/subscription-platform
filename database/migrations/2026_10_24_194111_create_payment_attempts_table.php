<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('kind')->default('retry'); // initial (Stripe's own first try), retry (scheduled), manual
            $table->string('status'); // succeeded, failed, requires_action
            $table->string('decline_code')->nullable();
            $table->string('decline_type')->nullable(); // soft, hard, auth_required
            $table->string('failure_message')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'attempt_number']);
            $table->index(['provider_id', 'attempted_at']);
            $table->unique(['invoice_id', 'stripe_payment_intent_id']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
