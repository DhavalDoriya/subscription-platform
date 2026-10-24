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
        Schema::create('email_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key')->index();
            $table->foreignUuid('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_email')->index();
            $table->string('subject');
            $table->text('html_body');
            $table->text('text_body');
            $table->string('status')->index(); // queued | sent | failed | skipped
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
