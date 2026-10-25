<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-PM6: nightly drift check between our subscriptions and Stripe.
Schedule::command('billing:reconcile')->dailyAt('02:30')->withoutOverlapping();

// FR-R7: failed invoices are retried on our own schedule (Stripe's retries are off); due ones are queued hourly.
Schedule::command('billing:retry-payments')->hourly()->withoutOverlapping();
// FR-R5: grace that has run out ends in the configured cancel or pause.
Schedule::command('billing:expire-grace')->hourly()->withoutOverlapping();

// FR-E1: trial-ending and upcoming-renewal reminders.
Schedule::command('billing:send-reminders')->dailyAt('09:00')->withoutOverlapping();

// FR-E1: nudge providers who have not finished connecting Stripe.
Schedule::command('providers:remind-stripe-onboarding')->dailyAt('09:15')->withoutOverlapping();

// T9.4: tell admins when webhooks fail or payments fail unusually often.
Schedule::command('platform:check-alerts')->everyFifteenMinutes()->withoutOverlapping();
