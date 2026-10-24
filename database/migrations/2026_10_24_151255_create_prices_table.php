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
        Schema::create('prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount'); // minor units
            $table->char('currency', 3);
            $table->string('interval'); // day, week, month, year
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->string('status')->default('active'); // active, archived
            $table->string('stripe_price_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['plan_id', 'status']);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
