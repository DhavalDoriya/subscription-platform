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
        Schema::create('page_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('storefront_page_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('data');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index(['storefront_page_id', 'position']);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_blocks');
    }
};
