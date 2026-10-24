<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Why a paused subscription is paused, so reactivating a provider resumes only its own pauses: provider (FR-P9) or dunning (FR-R5).
            $table->string('pause_reason')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('pause_reason');
        });
    }
};
