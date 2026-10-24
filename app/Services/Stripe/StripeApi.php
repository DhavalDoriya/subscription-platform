<?php

namespace App\Services\Stripe;

use App\Exceptions\ApiException;
use App\Services\Settings\SettingsService;
use Closure;
use Stripe\Exception\CardException;
use Stripe\StripeClient;

class StripeApi implements StripeGateway
{
    /** @param  Closure(): object  $client  returns the Stripe SDK client; resolved per call so key changes apply at once */
    public function __construct(private Closure $client) {}

    public static function clientFromSettings(): StripeClient
    {
        $key = app(SettingsService::class)->get('stripe.secret_key');

        if (! $key) {
            throw new ApiException(__('api.stripe.not_configured'), 503, 'stripe_not_configured');
        }

        return new StripeClient($key);
    }

    public function createExpressAccount(array $params, string $idempotencyKey): array
    {
        return StripeAccountState::normalize($this->client()->accounts->create($params, ['idempotency_key' => $idempotencyKey]));
    }

    public function retrieveAccount(string $accountId): array
    {
        return StripeAccountState::normalize($this->client()->accounts->retrieve($accountId));
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->client()->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->url;
    }

    public function createLoginLink(string $accountId): string
    {
        return $this->client()->accounts->createLoginLink($accountId)->url;
    }

    public function createProduct(string $accountId, array $params, string $idempotencyKey): string
    {
        return $this->client()->products->create($params, $this->opts($accountId, $idempotencyKey))->id;
    }

    public function updateProduct(string $accountId, string $productId, array $params, string $idempotencyKey): void
    {
        $this->client()->products->update($productId, $params, $this->opts($accountId, $idempotencyKey));
    }

    public function createPrice(string $accountId, array $params, string $idempotencyKey): string
    {
        return $this->client()->prices->create($params, $this->opts($accountId, $idempotencyKey))->id;
    }

    public function archivePrice(string $accountId, string $priceId, string $idempotencyKey): void
    {
        $this->client()->prices->update($priceId, ['active' => false], $this->opts($accountId, $idempotencyKey));
    }

    public function createCustomer(string $accountId, array $params, string $idempotencyKey): string
    {
        return $this->client()->customers->create($params, $this->opts($accountId, $idempotencyKey))->id;
    }

    public function createSubscription(string $accountId, array $params, string $idempotencyKey): array
    {
        $params['expand'] = ['latest_invoice.confirmation_secret', 'pending_setup_intent'];

        return StripeSubscriptionState::normalize($this->client()->subscriptions->create($params, $this->opts($accountId, $idempotencyKey)));
    }

    public function retrieveSubscription(string $accountId, string $subscriptionId): array
    {
        return StripeSubscriptionState::normalize($this->client()->subscriptions->retrieve($subscriptionId, null, ['stripe_account' => $accountId]));
    }

    public function updateSubscription(string $accountId, string $subscriptionId, array $params, string $idempotencyKey): array
    {
        return StripeSubscriptionState::normalize($this->client()->subscriptions->update($subscriptionId, $params, $this->opts($accountId, $idempotencyKey)));
    }

    public function cancelSubscription(string $accountId, string $subscriptionId, string $idempotencyKey): array
    {
        return StripeSubscriptionState::normalize($this->client()->subscriptions->cancel($subscriptionId, null, $this->opts($accountId, $idempotencyKey)));
    }

    public function listSubscriptions(string $accountId): array
    {
        $page = $this->client()->subscriptions->all(['status' => 'all', 'limit' => 100], ['stripe_account' => $accountId]);

        return array_map(StripeSubscriptionState::normalize(...), $page->data);
    }

    public function createSetupIntent(string $accountId, string $customerId, string $idempotencyKey): string
    {
        return $this->client()->setupIntents->create([
            'customer' => $customerId,
            'usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true],
        ], $this->opts($accountId, $idempotencyKey))->client_secret;
    }

    public function setDefaultPaymentMethod(string $accountId, string $customerId, string $paymentMethodId, string $idempotencyKey): void
    {
        $this->client()->customers->update($customerId, ['invoice_settings' => ['default_payment_method' => $paymentMethodId]], $this->opts($accountId, $idempotencyKey));
    }

    public function detachPaymentMethod(string $accountId, string $paymentMethodId, string $idempotencyKey): void
    {
        $this->client()->paymentMethods->detach($paymentMethodId, null, $this->opts($accountId, $idempotencyKey));
    }

    public function createRefund(string $accountId, string $paymentIntentId, ?int $amount, string $idempotencyKey, ?string $reason = null): array
    {
        $refund = $this->client()->refunds->create(
            array_filter(['payment_intent' => $paymentIntentId, 'amount' => $amount, 'reason' => $reason], fn ($v) => $v !== null),
            $this->opts($accountId, $idempotencyKey),
        );

        return ['id' => $refund->id, 'status' => $refund->status];
    }

    public function payInvoice(string $accountId, string $invoiceId, string $idempotencyKey): array
    {
        try {
            $invoice = $this->client()->invoices->pay($invoiceId, ['off_session' => true], $this->opts($accountId, $idempotencyKey));

            return ['status' => $invoice->status === 'paid' ? 'paid' : 'failed', 'decline_code' => null, 'failure_message' => null, 'payment_intent_id' => null];
        } catch (CardException $e) {
            $code = $e->getDeclineCode() ?? $e->getStripeCode();
            $intent = $e->getError()?->payment_intent ?? null;

            return [
                'status' => $code === 'authentication_required' ? 'requires_action' : 'failed',
                'decline_code' => $code,
                'failure_message' => $e->getMessage(),
                'payment_intent_id' => is_object($intent) ? ($intent->id ?? null) : null,
            ];
        }
    }

    public function updateInvoice(string $accountId, string $invoiceId, array $params, string $idempotencyKey): void
    {
        $this->client()->invoices->update($invoiceId, $params, $this->opts($accountId, $idempotencyKey));
    }

    /** Direct charges: every catalogue call runs on the provider's connected account. */
    private function opts(string $accountId, string $idempotencyKey): array
    {
        return ['stripe_account' => $accountId, 'idempotency_key' => $idempotencyKey];
    }

    private function client(): object
    {
        return ($this->client)();
    }
}
