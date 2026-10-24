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
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('features');
            $table->string('status')->default('draft'); // draft, active, archived
            $table->string('visibility')->default('public'); // public, unlisted
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('stripe_product_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
