<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size'); // bytes, after optimisation
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('alt')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'created_at']);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
