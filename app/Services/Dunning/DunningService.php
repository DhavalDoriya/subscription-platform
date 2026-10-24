<?php

namespace App\Services\Dunning;

use App\Enums\DeclineType;
use App\Enums\ProviderStatus;
use App\Enums\SubscriptionStatus;
use App\Models\DunningEvent;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionSync;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The retry and dunning engine (FR-R1 to R9). Stripe's own retries are off, so every failed invoice is walked through
 * our schedule here: soft declines are retried on `dunning.retry_days`, hard and authentication declines wait for the
 * customer, and both end in grace and then the configured final action. All state lives on the invoice
 * (`dunning_status`, `next_retry_at`) plus `payment_attempts` and `dunning_events`, so the scheduler only has to ask
 * "which invoices are due?".
 */
class DunningService
{
    /** Invoice states in which dunning is still open. */
    public const ACTIVE = ['retrying', 'awaiting_customer', 'exhausted'];

    public function __construct(
        private DunningSettings $settings,
        private DeclineClassifier $classifier,
        private StripeGateway $stripe,
        private SubscriptionSync $sync,
        private DunningNotifier $notifier,
    ) {}

    /**
     * Records a failed collection attempt and decides what happens next. Safe to call twice for the same payment intent.
     */
    public function recordFailure(Invoice $invoice, ?string $code, ?string $message, ?string $paymentIntentId = null, string $kind = 'initial'): PaymentAttempt
    {
        return DB::transaction(function () use ($invoice, $code, $message, $paymentIntentId, $kind) {
            if ($paymentIntentId && ($existing = PaymentAttempt::where('invoice_id', $invoice->id)->where('stripe_payment_intent_id', $paymentIntentId)->first())) {
                return $existing;
            }

            $type = $this->classifier->classify($code);
            $first = $invoice->first_failed_at === null;
            $failed = PaymentAttempt::where('invoice_id', $invoice->id)->where('status', '!=', 'succeeded')->count() + 1;
            $subscription = $invoice->subscription;

            $invoice->first_failed_at ??= now();
            $next = $type->isRetryable() ? $this->nextRetryAt($invoice->first_failed_at, $failed) : null;

            $attempt = PaymentAttempt::create([
                'provider_id' => $invoice->provider_id,
                'invoice_id' => $invoice->id,
                'subscription_id' => $invoice->subscription_id,
                'attempt_number' => (PaymentAttempt::where('invoice_id', $invoice->id)->max('attempt_number') ?? 0) + 1,
                'kind' => $first ? 'initial' : $kind,
                'status' => $type === DeclineType::AuthRequired ? 'requires_action' : 'failed',
                'decline_code' => $code,
                'decline_type' => $type,
                'failure_message' => $message,
                'stripe_payment_intent_id' => $paymentIntentId,
                'attempted_at' => now(),
                'next_retry_at' => $next,
            ]);

            if ($subscription && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
                $subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();
            }

            // Save the new state before announcing it, so the notices can quote the retry date.
            $invoice->forceFill([
                'dunning_status' => $next ? 'retrying' : ($type->needsCustomerAction() ? 'awaiting_customer' : 'exhausted'),
                'next_retry_at' => $next,
            ])->save();

            if ($first) {
                $this->event($invoice, 'failure_notice', ['decline_code' => $code, 'decline_type' => $type->value]);
            }

            if ($next) {
                $this->event($invoice, 'retry_scheduled', ['attempt' => $attempt->attempt_number + 1, 'at' => $next->toIso8601String()]);

                return $attempt;
            }

            if ($type->needsCustomerAction()) {
                $this->event($invoice, $type === DeclineType::AuthRequired ? 'auth_required' : 'new_card_requested', ['url' => $invoice->hosted_invoice_url]);
            }

            $this->startGrace($invoice, $subscription);

            return $attempt;
        });
    }

    /**
     * The decline reason reached us after the failed invoice did (webhook order is not guaranteed): fill it into the
     * attempt that has none and, if it turns out the customer must act, stop retrying and start grace.
     */
    public function reclassify(Invoice $invoice, string $paymentIntentId, ?string $code, ?string $message): void
    {
        $attempt = PaymentAttempt::where('invoice_id', $invoice->id)->whereNull('stripe_payment_intent_id')->whereNull('decline_code')->latest('attempt_number')->first();

        if (! $attempt || ! in_array($invoice->dunning_status, self::ACTIVE, true)) {
            return;
        }

        $type = $this->classifier->classify($code);
        $attempt->forceFill(['stripe_payment_intent_id' => $paymentIntentId, 'decline_code' => $code, 'failure_message' => $message, 'decline_type' => $type]);

        if ($type->needsCustomerAction()) {
            $attempt->next_retry_at = null;
            $attempt->status = $type === DeclineType::AuthRequired ? 'requires_action' : 'failed';
            $invoice->forceFill(['dunning_status' => 'awaiting_customer', 'next_retry_at' => null])->save();
            $this->event($invoice, $type === DeclineType::AuthRequired ? 'auth_required' : 'new_card_requested', ['url' => $invoice->hosted_invoice_url]);
            $this->startGrace($invoice, $invoice->subscription);
        }

        $attempt->save();
    }

    /**
     * Charges the invoice now if it is due (or always, for a manual retry), under a per-invoice lock so two workers
     * cannot both charge. Returns the attempt, or null when nothing was done.
     */
    public function retry(Invoice $invoice, bool $manual = false, string $kind = 'manual'): ?PaymentAttempt
    {
        $lock = Cache::lock("dunning:invoice:{$invoice->id}", 120);

        if (! $lock->get()) {
            return null;
        }

        try {
            $invoice = Invoice::with('subscription.provider')->find($invoice->id);

            if (! $invoice || ! $this->canCharge($invoice, $manual)) {
                return null;
            }

            $number = (PaymentAttempt::where('invoice_id', $invoice->id)->max('attempt_number') ?? 0) + 1;
            $result = $this->stripe->payInvoice($invoice->subscription->provider->stripe_account_id, $invoice->stripe_invoice_id, "dunning-{$invoice->id}-{$number}");
            $kind = $manual ? $kind : 'retry';

            if ($result['status'] === 'paid') {
                return $this->recordSuccess($invoice, $kind, $number, $result['payment_intent_id'] ?? null);
            }

            return $this->recordFailure(
                $invoice,
                $result['status'] === 'requires_action' ? 'authentication_required' : $result['decline_code'],
                $result['failure_message'],
                $result['payment_intent_id'],
                $kind,
            );
        } finally {
            $lock->release();
        }
    }

    /** Whether a manual retry makes sense: the invoice is still open and its subscription is live and not paused by its provider. */
    public function canRetry(Invoice $invoice): bool
    {
        return $this->canCharge($invoice->loadMissing('subscription.provider'), true);
    }

    /**
     * FR-R8: the customer gave us a working card, so every open failed invoice of theirs is retried now, not on the
     * schedule. A failing retry is recorded like any other and never stops the card change that triggered it.
     */
    public function retryOpenInvoices(ProviderCustomer $link): void
    {
        Invoice::where('provider_customer_id', $link->id)->where('status', 'open')->whereIn('dunning_status', self::ACTIVE)->each(function (Invoice $invoice) {
            try {
                $this->retry($invoice, manual: true, kind: 'card_update');
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** The invoice was paid (by our retry, the customer, or Stripe): dunning ends and the subscription recovers. */
    public function markRecovered(Invoice $invoice): void
    {
        if (! in_array($invoice->dunning_status, self::ACTIVE, true)) {
            return;
        }

        $invoice->forceFill(['dunning_status' => 'recovered', 'next_retry_at' => null])->save();
        $this->reactivate($invoice->subscription);
        $this->event($invoice, 'recovered');
    }

    /** The provider says it is settled outside the platform (FR-R9): stop dunning, keep the subscription going. */
    public function resolve(Invoice $invoice): void
    {
        $invoice->forceFill(['dunning_status' => 'resolved', 'next_retry_at' => null])->save();
        $this->reactivate($invoice->subscription);
        $this->event($invoice, 'resolved');
    }

    /** Applies the final action to every subscription whose grace has run out. Returns how many were ended. */
    public function expireGrace(): int
    {
        // A provider that is paused keeps its customers' grace frozen: nothing ends while billing is off (FR-P9).
        $due = Subscription::where('status', SubscriptionStatus::Grace->value)->where('grace_ends_at', '<=', now())
            ->whereHas('provider', fn ($provider) => $provider->whereNotIn('status', [ProviderStatus::Inactive->value, ProviderStatus::Suspended->value]))
            ->get();

        foreach ($due as $subscription) {
            $this->applyFinalAction($subscription, $this->dunningInvoice($subscription));
        }

        return $due->count();
    }

    private function recordSuccess(Invoice $invoice, string $kind, int $number, ?string $paymentIntentId): PaymentAttempt
    {
        $attempt = PaymentAttempt::create([
            'provider_id' => $invoice->provider_id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription_id,
            'attempt_number' => $number,
            'kind' => $kind,
            'status' => 'succeeded',
            'stripe_payment_intent_id' => $paymentIntentId,
            'attempted_at' => now(),
        ]);

        $invoice->forceFill(['status' => 'paid', 'amount_paid' => $invoice->amount_due, 'paid_at' => now()])->save();
        $this->markRecovered($invoice);

        return $attempt;
    }

    private function startGrace(Invoice $invoice, ?Subscription $subscription): void
    {
        if (! $subscription || $subscription->status === SubscriptionStatus::Grace) {
            return;
        }

        if ($this->settings->graceDays() === 0) {
            $this->applyFinalAction($subscription, $invoice);

            return;
        }

        $subscription->forceFill(['status' => SubscriptionStatus::Grace, 'grace_ends_at' => now()->addDays($this->settings->graceDays())])->save();
        $this->event($invoice, 'grace_started', ['ends_at' => $subscription->grace_ends_at->toIso8601String()]);
    }

    private function applyFinalAction(Subscription $subscription, ?Invoice $invoice): void
    {
        $account = $subscription->provider->stripe_account_id;
        $pause = $this->settings->finalAction() === 'pause';

        $state = $pause
            ? $this->stripe->updateSubscription($account, $subscription->stripe_subscription_id, ['pause_collection' => ['behavior' => 'void']], "dunning-pause-{$subscription->id}")
            : $this->stripe->cancelSubscription($account, $subscription->stripe_subscription_id, "dunning-cancel-{$subscription->id}");

        $this->sync->apply($subscription, $state);
        $subscription->forceFill(['grace_ends_at' => null, 'pause_reason' => $pause ? 'dunning' : null])->save();

        if ($invoice) {
            $this->event($invoice, $pause ? 'paused' : 'canceled');
        }
    }

    /** Back to a normal active subscription once the debt is cleared; paused or ended ones stay as they are. */
    private function reactivate(?Subscription $subscription): void
    {
        if ($subscription && in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Grace], true)) {
            $subscription->forceFill(['status' => SubscriptionStatus::Active, 'grace_ends_at' => null])->save();
        }
    }

    private function canCharge(Invoice $invoice, bool $manual): bool
    {
        $subscription = $invoice->subscription;

        if ($invoice->status !== 'open' || ! $subscription?->status->isLive() || $subscription->provider->status->pausesBilling()) {
            return false;
        }

        if ($manual) {
            return true;
        }

        return $invoice->dunning_status === 'retrying' && $invoice->next_retry_at !== null && $invoice->next_retry_at->lte(now());
    }

    private function nextRetryAt(Carbon $firstFailedAt, int $failures): ?Carbon
    {
        $days = $this->settings->retryDays()[$failures - 1] ?? null;

        return $days ? $firstFailedAt->copy()->addDays($days) : null;
    }

    private function dunningInvoice(Subscription $subscription): ?Invoice
    {
        return Invoice::where('subscription_id', $subscription->id)->whereIn('dunning_status', self::ACTIVE)->latest('first_failed_at')->first();
    }

    /** @param  array<string, mixed>  $payload */
    private function event(Invoice $invoice, string $type, array $payload = []): DunningEvent
    {
        $payload += $this->notifier->notify($invoice, $type, $payload);

        return DunningEvent::create([
            'provider_id' => $invoice->provider_id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription_id,
            'type' => $type,
            'payload' => $payload ?: null,
            'occurred_at' => now(),
        ]);
    }
}
