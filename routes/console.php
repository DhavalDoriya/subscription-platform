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
