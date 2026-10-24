<?php

namespace App\Services\Stripe;

use App\Exceptions\ApiException;
use App\Services\Settings\SettingsService;
use Closure;
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
