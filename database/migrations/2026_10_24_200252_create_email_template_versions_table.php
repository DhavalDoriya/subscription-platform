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
        Schema::create('email_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('email_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('subject');
            $table->text('html_body');
            $table->text('text_body');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['email_template_id', 'version']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_template_versions');
    }
};
