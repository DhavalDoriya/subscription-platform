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
