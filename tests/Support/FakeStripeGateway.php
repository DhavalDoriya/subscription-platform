<?php

namespace Tests\Support;

use App\Services\Stripe\StripeAccountState;
use App\Services\Stripe\StripeGateway;

/**
 * In-memory Stripe for tests: records every call (with its idempotency key) and returns deterministic data.
 * Bound for every Feature test in tests/Pest.php, so no test can reach the real API.
 */
class FakeStripeGateway implements StripeGateway
{
    /** @var array<int, array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> account id => raw Stripe account */
    public array $accounts = [];

    /** @var array<string, array<string, mixed>> subscription id => normalised subscription, steerable via setSubscription() */
    public array $subscriptions = [];

    /** Status the next created subscription starts in (Stripe: incomplete, or trialing when the plan has a trial). */
    public ?string $nextSubscriptionStatus = null;

    /** @var array<string, array<int, array<string, mixed>>> invoice id => queued results of payInvoice(); an empty queue pays */
    public array $invoicePayments = [];

    private int $sequence = 0;

    public function createExpressAccount(array $params, string $idempotencyKey): array
    {
        $id = 'acct_fake'.++$this->sequence;
        $this->accounts[$id] = ['id' => $id, 'details_submitted' => false, 'charges_enabled' => false, 'payouts_enabled' => false, 'requirements' => ['disabled_reason' => null, 'currently_due' => ['external_account']]];
        $this->record('createExpressAccount', ['params' => $params, 'idempotency_key' => $idempotencyKey]);

        return StripeAccountState::normalize($this->accounts[$id]);
    }

    public function retrieveAccount(string $accountId): array
    {
        $this->record('retrieveAccount', ['account' => $accountId]);

        return StripeAccountState::normalize($this->accounts[$accountId] ?? ['id' => $accountId]);
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        $this->record('createAccountLink', ['account' => $accountId, 'refresh_url' => $refreshUrl, 'return_url' => $returnUrl]);

        return "https://connect.stripe.test/onboarding/{$accountId}";
    }

    public function createLoginLink(string $accountId): string
    {
        $this->record('createLoginLink', ['account' => $accountId]);

        return "https://dashboard.stripe.test/express/{$accountId}";
    }

    public function createProduct(string $accountId, array $params, string $idempotencyKey): string
    {
        $this->record('createProduct', ['account' => $accountId, 'params' => $params, 'idempotency_key' => $idempotencyKey]);

        return 'prod_fake'.++$this->sequence;
    }

    public function updateProduct(string $accountId, string $productId, array $params, string $idempotencyKey): void
    {
        $this->record('updateProduct', ['account' => $accountId, 'product' => $productId, 'params' => $params, 'idempotency_key' => $idempotencyKey]);
    }

    public function createPrice(string $accountId, array $params, string $idempotencyKey): string
    {
        $this->record('createPrice', ['account' => $accountId, 'params' => $params, 'idempotency_key' => $idempotencyKey]);

        return 'price_fake'.++$this->sequence;
    }

    public function archivePrice(string $accountId, string $priceId, string $idempotencyKey): void
    {
        $this->record('archivePrice', ['account' => $accountId, 'price' => $priceId, 'idempotency_key' => $idempotencyKey]);
    }

    public function createCustomer(string $accountId, array $params, string $idempotencyKey): string
    {
        $this->record('createCustomer', ['account' => $accountId, 'params' => $params, 'idempotency_key' => $idempotencyKey]);

        return 'cus_fake'.++$this->sequence;
    }

    public function createSubscription(string $accountId, array $params, string $idempotencyKey): array
    {
        $this->record('createSubscription', ['account' => $accountId, 'params' => $params, 'idempotency_key' => $idempotencyKey]);

        $trial = (int) ($params['trial_period_days'] ?? 0);
        $id = 'sub_fake'.++$this->sequence;
        $start = time();

        return $this->subscriptions[$id] = [
            'id' => $id,
            'customer_id' => $params['customer'],
            'status' => $this->nextSubscriptionStatus ?? ($trial ? 'trialing' : 'incomplete'),
            'price_id' => $params['items'][0]['price'] ?? null,
            'item_id' => 'si_fake'.$this->sequence,
            'current_period_start' => $start,
            'current_period_end' => $start + ($trial ?: 30) * 86400,
            'trial_end' => $trial ? $start + $trial * 86400 : null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'paused' => false,
            'latest_invoice_id' => 'in_fake'.$this->sequence,
            'client_secret' => ($trial ? 'seti_' : 'pi_').$this->sequence.'_secret_fake',
            'client_secret_type' => $trial ? 'setup' : 'payment',
        ];
    }

    public function retrieveSubscription(string $accountId, string $subscriptionId): array
    {
        $this->record('retrieveSubscription', ['account' => $accountId, 'subscription' => $subscriptionId]);

        return $this->subscriptions[$subscriptionId];
    }

    public function updateSubscription(string $accountId, string $subscriptionId, array $params, string $idempotencyKey): array
    {
        $this->record('updateSubscription', ['account' => $accountId, 'subscription' => $subscriptionId, 'params' => $params, 'idempotency_key' => $idempotencyKey]);

        $sub = &$this->subscriptions[$subscriptionId];
        $sub['cancel_at_period_end'] = $params['cancel_at_period_end'] ?? $sub['cancel_at_period_end'];
        $sub['price_id'] = $params['items'][0]['price'] ?? $sub['price_id'];
        if (array_key_exists('pause_collection', $params)) {
            $sub['paused'] = $params['pause_collection'] !== '' && $params['pause_collection'] !== null;
        }

        return $sub;
    }

    public function cancelSubscription(string $accountId, string $subscriptionId, string $idempotencyKey): array
    {
        $this->record('cancelSubscription', ['account' => $accountId, 'subscription' => $subscriptionId, 'idempotency_key' => $idempotencyKey]);

        $this->subscriptions[$subscriptionId]['status'] = 'canceled';
        $this->subscriptions[$subscriptionId]['ended_at'] = $this->subscriptions[$subscriptionId]['canceled_at'] = time();

        return $this->subscriptions[$subscriptionId];
    }

    public function listSubscriptions(string $accountId): array
    {
        $this->record('listSubscriptions', ['account' => $accountId]);

        return array_values($this->subscriptions);
    }

    public function createSetupIntent(string $accountId, string $customerId, string $idempotencyKey): string
    {
        $this->record('createSetupIntent', ['account' => $accountId, 'customer' => $customerId, 'idempotency_key' => $idempotencyKey]);

        return 'seti_'.++$this->sequence.'_secret_fake';
    }

    public function setDefaultPaymentMethod(string $accountId, string $customerId, string $paymentMethodId, string $idempotencyKey): void
    {
        $this->record('setDefaultPaymentMethod', ['account' => $accountId, 'customer' => $customerId, 'payment_method' => $paymentMethodId, 'idempotency_key' => $idempotencyKey]);
    }

    public function detachPaymentMethod(string $accountId, string $paymentMethodId, string $idempotencyKey): void
    {
        $this->record('detachPaymentMethod', ['account' => $accountId, 'payment_method' => $paymentMethodId, 'idempotency_key' => $idempotencyKey]);
    }

    public function createRefund(string $accountId, string $paymentIntentId, ?int $amount, string $idempotencyKey, ?string $reason = null, bool $refundApplicationFee = false): array
    {
        $this->record('createRefund', ['account' => $accountId, 'payment_intent' => $paymentIntentId, 'amount' => $amount, 'reason' => $reason, 'refund_application_fee' => $refundApplicationFee, 'idempotency_key' => $idempotencyKey]);

        return ['id' => 're_fake'.++$this->sequence, 'status' => 'succeeded'];
    }

    public function refundApplicationFeeForCharge(string $chargeId, string $idempotencyKey): void
    {
        $this->record('refundApplicationFeeForCharge', ['charge' => $chargeId, 'idempotency_key' => $idempotencyKey]);
    }

    public function payInvoice(string $accountId, string $invoiceId, string $idempotencyKey): array
    {
        $this->record('payInvoice', ['account' => $accountId, 'invoice' => $invoiceId, 'idempotency_key' => $idempotencyKey]);

        $queued = $this->invoicePayments[$invoiceId] ?? [];
        $next = array_shift($queued);
        $this->invoicePayments[$invoiceId] = $queued;

        return $next ?? ['status' => 'paid', 'decline_code' => null, 'failure_message' => null, 'payment_intent_id' => 'pi_fake'.++$this->sequence];
    }

    public function updateInvoice(string $accountId, string $invoiceId, array $params, string $idempotencyKey): void
    {
        $this->record('updateInvoice', ['account' => $accountId, 'invoice' => $invoiceId, 'params' => $params, 'idempotency_key' => $idempotencyKey]);
    }

    /** Queues a declined card for the next payInvoice() of this invoice. */
    public function declineInvoice(string $invoiceId, string $declineCode, ?string $paymentIntentId = null): void
    {
        $this->invoicePayments[$invoiceId][] = [
            'status' => $declineCode === 'authentication_required' ? 'requires_action' : 'failed',
            'decline_code' => $declineCode,
            'failure_message' => "Your card was declined ({$declineCode}).",
            'payment_intent_id' => $paymentIntentId ?? 'pi_fake'.++$this->sequence,
        ];
    }

    /** Simulates the provider finishing (or failing) onboarding on Stripe's side. */
    public function setAccount(string $id, array $overrides): void
    {
        $this->accounts[$id] = array_merge($this->accounts[$id] ?? ['id' => $id], $overrides);
    }

    /** @return array<int, array<string, mixed>> */
    public function callsTo(string $method): array
    {
        return array_values(array_map(fn ($c) => $c['args'], array_filter($this->calls, fn ($c) => $c['method'] === $method)));
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
