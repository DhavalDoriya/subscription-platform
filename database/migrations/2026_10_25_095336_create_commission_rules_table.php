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
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->nullable()->constrained(); // null = the global default
            $table->decimal('percent', 5, 2); // whole platform fee in percent: Stripe subscriptions only take a percent, never a fixed amount
            $table->timestamp('effective_from');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->index(['provider_id', 'effective_from']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
