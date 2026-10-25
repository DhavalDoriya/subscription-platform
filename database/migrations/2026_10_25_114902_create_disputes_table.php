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
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained();
            $table->foreignUuid('payment_id')->constrained();
            $table->string('stripe_dispute_id')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('reason')->nullable();
            $table->string('status'); // needs_response, under_review, won, lost, warning_*, charge_refunded
            $table->timestamp('evidence_due_by')->nullable();
            $table->unsignedBigInteger('stripe_event_at')->nullable();
            $table->index(['provider_id', 'status']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
