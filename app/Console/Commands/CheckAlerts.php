<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Mail\AdminAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:check-alerts')]
#[Description('Email admins when Stripe webhooks are failing or the share of failed payments is unusually high (T9.4)')]
class CheckAlerts extends Command
{
    /** Failed payments only mean something once there are enough of them. */
    private const MIN_PAYMENTS = 10;

    private const FAILURE_RATE_PERCENT = 30;

    public function handle(AdminAlerts $alerts): int
    {
        $since = now()->subHour();
        $admin = rtrim((string) config('app.frontend_url'), '/').'/admin';

        $failedEvents = WebhookEvent::where('status', 'failed')->where('updated_at', '>=', $since)->count();
        if ($failedEvents > 0 && $alerts->send('admin.webhook_failures', ['count' => $failedEvents, 'period' => 'the last hour', 'admin_url' => $admin.'/webhooks'], 'hourly')) {
            $this->warn("{$failedEvents} webhook events failed: admins notified.");
        }

        $payments = Payment::withoutGlobalScopes()->where('created_at', '>=', $since)->whereIn('status', ['succeeded', 'failed', 'refunded', 'partially_refunded'])->count();
        $failed = Payment::withoutGlobalScopes()->where('created_at', '>=', $since)->where('status', 'failed')->count();
        $rate = $payments > 0 ? (int) round($failed / $payments * 100) : 0;

        if ($payments >= self::MIN_PAYMENTS && $rate >= self::FAILURE_RATE_PERCENT && $alerts->send('admin.high_failure_rate', ['rate' => $rate, 'period' => 'the last hour', 'admin_url' => $admin.'/invoices'], 'hourly')) {
            $this->warn("{$rate}% of payments failed: admins notified.");
        }

        return self::SUCCESS;
    }
}
