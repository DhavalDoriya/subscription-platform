<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:send-reminders')]
#[Description('Email customers before a trial ends or a subscription renews (FR-E1)')]
class SendBillingReminders extends Command
{
    private const DAYS_AHEAD = 3;

    public function handle(SubscriptionNotifier $notifier): int
    {
        $horizon = now()->addDays(self::DAYS_AHEAD);
        $sent = 0;

        // A reminder is recorded against the date it announces, so a later trial end or billing period gets its own.
        $trials = Subscription::withoutGlobalScopes()->where('status', SubscriptionStatus::Trialing)
            ->whereBetween('trial_ends_at', [now(), $horizon])->where('cancel_at_period_end', false)->get();
        foreach ($trials as $subscription) {
            if ($subscription->trial_reminder_for?->eq($subscription->trial_ends_at)) {
                continue;
            }
            $subscription->forceFill(['trial_reminder_for' => $subscription->trial_ends_at])->save();
            $notifier->reminder($subscription, 'trial');
            $sent++;
        }

        $renewals = Subscription::withoutGlobalScopes()->where('status', SubscriptionStatus::Active)->where('cancel_at_period_end', false)
            ->whereBetween('current_period_end', [now(), $horizon])->get();
        foreach ($renewals as $subscription) {
            if ($subscription->renewal_reminder_for?->eq($subscription->current_period_end)) {
                continue;
            }
            $subscription->forceFill(['renewal_reminder_for' => $subscription->current_period_end])->save();
            $notifier->reminder($subscription, 'renewal');
            $sent++;
        }

        $this->info("Sent {$sent} reminders.");

        return self::SUCCESS;
    }
}
