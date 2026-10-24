<?php

namespace App\Services\Stripe;

/**
 * The only door to Stripe. Everything returns plain arrays/strings (never SDK objects) and every write takes an
 * idempotency key, so a retried request cannot create a second object. Tests bind Tests\Support\FakeStripeGateway.
 */
interface StripeGateway
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{id: string, details_submitted: bool, charges_enabled: bool, payouts_enabled: bool, disabled_reason: ?string, currently_due: array<int, string>}
     */
    public function createExpressAccount(array $params, string $idempotencyKey): array;

    /** @return array{id: string, details_submitted: bool, charges_enabled: bool, payouts_enabled: bool, disabled_reason: ?string, currently_due: array<int, string>} */
    public function retrieveAccount(string $accountId): array;

    /** Stripe-hosted onboarding page for a connected account. */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string;

    /** One-time login into the connected account's Express dashboard. */
    public function createLoginLink(string $accountId): string;

    /** Creates a product ON the connected account; returns its id. */
    public function createProduct(string $accountId, array $params, string $idempotencyKey): string;

    public function updateProduct(string $accountId, string $productId, array $params, string $idempotencyKey): void;

    /** Creates a recurring price ON the connected account; returns its id. Prices are immutable, so changes mean a new price. */
    public function createPrice(string $accountId, array $params, string $idempotencyKey): string;

    /** Stops a price being used for new subscriptions; existing subscribers keep it. */
    public function archivePrice(string $accountId, string $priceId, string $idempotencyKey): void;
}
