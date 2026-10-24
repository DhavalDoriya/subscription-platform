<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('provider_customer_id')->constrained()->cascadeOnDelete();
            $table->string('brand')->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('stripe_payment_method_id')->unique();
            $table->timestamps();

            $table->index('provider_customer_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
