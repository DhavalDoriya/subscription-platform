<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('provider_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained();
            $table->foreignUuid('price_id')->constrained();
            // incomplete, trialing, active, past_due, grace, paused, canceled, expired
            $table->string('status')->default('incomplete');
            $table->unsignedBigInteger('amount'); // minor units, snapshot of the price at purchase/change
            $table->char('currency', 3);
            $table->string('interval');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->unsignedBigInteger('stripe_event_at')->nullable(); // `created` of the last Stripe event applied; older events are ignored
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index('provider_customer_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
