<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('reason')->nullable();
            $table->string('status')->default('succeeded'); // pending, succeeded, failed
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->timestamps();

            $table->index('payment_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
