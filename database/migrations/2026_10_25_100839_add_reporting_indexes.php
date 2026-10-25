<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T9.3: indexes for the queries the schedulers, alerts, dashboards and sorted lists run. */
return new class extends Migration
{
    private const INDEXES = [
        'subscriptions' => [['status', 'current_period_end'], ['status', 'trial_ends_at'], ['status', 'grace_ends_at']],
        'payments' => [['provider_id', 'created_at'], ['status', 'created_at']],
        'invoices' => [['provider_id', 'created_at']],
        'commissions' => [['provider_id', 'created_at'], ['created_at']],
        'webhook_events' => [['status', 'updated_at']],
        'email_logs' => [['created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $columns) {
                    $table->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $columns) {
                    $table->dropIndex($columns);
                }
            });
        }
    }
};
