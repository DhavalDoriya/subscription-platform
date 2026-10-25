<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Mail\TemplateMail;
use Throwable;

/**
 * Turns subscription changes into emails (FR-E1) by template key: the customer's confirmations and the provider owner's
 * heads-up. Called by SubscriptionSync, so checkout, webhooks and reconciliation all notify the same way. A mail
 * failure is reported and never stops the sync.
 */
class SubscriptionNotifier
{
    public function __construct(private TemplateMail $mail) {}

    /** @param  array<string, mixed>  $before  status, cancel_at_period_end and price_id of the subscription before the sync */
    public function changed(Subscription $subscription, array $before): void
    {
        $status = $subscription->status;
        $wasLive = $before['status']->isLive();
        $nowLive = $status->isLive();

        try {
            if (! $wasLive && $nowLive && in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true) && $before['status'] === SubscriptionStatus::Incomplete) {
                $this->toCustomer($subscription, 'customer.subscription_started');
                $this->toOwner($subscription, 'provider.new_subscriber');
            } elseif (($wasLive && $status === SubscriptionStatus::Canceled) || (! $before['cancel_at_period_end'] && $subscription->cancel_at_period_end && $nowLive)) {
                $this->toCustomer($subscription, 'customer.subscription_canceled');
                $this->toOwner($subscription, 'provider.plan_canceled');
            } elseif ($before['cancel_at_period_end'] && ! $subscription->cancel_at_period_end && $nowLive) {
                $this->toCustomer($subscription, 'customer.subscription_resumed');
            } elseif ($nowLive && $before['price_id'] !== $subscription->price_id) {
                $this->toCustomer($subscription, 'customer.plan_changed', ['old_plan.name' => $before['plan_name']]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** FR-E1: receipt for a paid invoice, once. Zero-amount and recovered (dunned) invoices get none: the recovery notice covers those. */
    public function receipt(Invoice $invoice): void
    {
        $subscription = $invoice->subscription;

        if (! $subscription || $invoice->receipt_sent_at || $invoice->amount_paid < 1 || $invoice->first_failed_at) {
            return;
        }

        $invoice->forceFill(['receipt_sent_at' => now()])->save();

        try {
            $this->toCustomer($subscription, 'customer.receipt', [
                'amount' => number_format($invoice->amount_paid / 100, 2, '.', '').' '.strtoupper($invoice->currency),
                'paid_at' => $invoice->paid_at?->toDateString(),
                'invoice_url' => $invoice->hosted_invoice_url,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** FR-E1: the customer is warned before a trial converts (`trial`) or a subscription renews (`renewal`). */
    public function reminder(Subscription $subscription, string $kind): void
    {
        try {
            $this->toCustomer($subscription, $kind === 'trial' ? 'customer.trial_ending' : 'customer.upcoming_renewal', ['renewal_date' => $subscription->current_period_end?->toDateString()]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function toCustomer(Subscription $subscription, string $template, array $extra = []): void
    {
        $user = $subscription->providerCustomer?->user;

        if ($user) {
            $this->mail->send($template, $user->email, $extra + $this->variables($subscription) + ['user.name' => $user->name], $subscription->provider_id);
        }
    }

    private function toOwner(Subscription $subscription, string $template): void
    {
        $owner = $subscription->provider?->owner();

        if ($owner) {
            $this->mail->send($template, $owner->email, $this->variables($subscription) + ['user.name' => $owner->name], $subscription->provider_id);
        }
    }

    /** @return array<string, mixed> */
    private function variables(Subscription $subscription): array
    {
        $subscription->unsetRelation('plan'); // a plan change moved plan_id after the relation may have been loaded
        $subscription->loadMissing(['provider', 'providerCustomer.user', 'plan']);
        $customer = $subscription->providerCustomer->user;
        $end = $subscription->current_period_end?->toDateString();

        return [
            'customer.name' => $customer->name,
            'customer.email' => $customer->email,
            'provider.name' => $subscription->provider->name,
            'plan.name' => $subscription->plan?->name,
            'amount' => number_format($subscription->amount / 100, 2, '.', '').' '.strtoupper($subscription->currency),
            'next_billing_date' => $end,
            'trial_ends_at' => $subscription->trial_ends_at?->toDateString(),
            'ends_at' => $subscription->ended_at?->toDateString() ?? $end,
        ];
    }
}
