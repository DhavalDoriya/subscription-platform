<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('provider_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->nullable();
            $table->string('status')->default('draft'); // draft, open, paid, void, uncollectible
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_due')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('hosted_invoice_url')->nullable();
            $table->string('invoice_pdf_url')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->unsignedBigInteger('stripe_event_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index('subscription_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
