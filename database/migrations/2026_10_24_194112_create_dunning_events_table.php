<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // failure_notice, retry_scheduled, retry_reminder, auth_required, new_card_requested, grace_started, final_notice, canceled, paused, recovered, resolved
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['invoice_id', 'occurred_at']);
            $table->index(['provider_id', 'type', 'occurred_at']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_events');
    }
};
