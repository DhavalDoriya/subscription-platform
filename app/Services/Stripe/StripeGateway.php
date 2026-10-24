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

    /** Creates a Stripe customer ON the connected account (direct charges); returns its id. */
    public function createCustomer(string $accountId, array $params, string $idempotencyKey): string;

    /**
     * Starts a subscription that waits for the customer to confirm payment (payment_behavior default_incomplete).
     *
     * @return array<string, mixed> StripeSubscriptionState::normalize() shape, including the client secret for Payment Element
     */
    public function createSubscription(string $accountId, array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> StripeSubscriptionState shape */
    public function retrieveSubscription(string $accountId, string $subscriptionId): array;

    /** @return array<string, mixed> StripeSubscriptionState shape */
    public function updateSubscription(string $accountId, string $subscriptionId, array $params, string $idempotencyKey): array;

    /** Ends the subscription now. @return array<string, mixed> StripeSubscriptionState shape */
    public function cancelSubscription(string $accountId, string $subscriptionId, string $idempotencyKey): array;

    /** Every subscription of the account, for the nightly reconciliation (FR-PM6). @return array<int, array<string, mixed>> */
    public function listSubscriptions(string $accountId): array;

    /** A SetupIntent for adding a card outside of checkout; returns its client secret. */
    public function createSetupIntent(string $accountId, string $customerId, string $idempotencyKey): string;

    public function setDefaultPaymentMethod(string $accountId, string $customerId, string $paymentMethodId, string $idempotencyKey): void;

    public function detachPaymentMethod(string $accountId, string $paymentMethodId, string $idempotencyKey): void;

    /** Refunds a charge (all of it when $amount is null). @return array{id: string, status: string} */
    public function createRefund(string $accountId, string $paymentIntentId, ?int $amount, string $idempotencyKey, ?string $reason = null): array;

    /**
     * Charges an open invoice now with the customer's default payment method (our retry engine, FR-R7). A declined card
     * is a normal outcome, not an exception.
     *
     * @return array{status: string, decline_code: ?string, failure_message: ?string, payment_intent_id: ?string} status is paid, failed or requires_action
     */
    public function payInvoice(string $accountId, string $invoiceId, string $idempotencyKey): array;

    /** Changes an invoice, e.g. `auto_advance => false` so Stripe stops retrying it itself (FR-R1). */
    public function updateInvoice(string $accountId, string $invoiceId, array $params, string $idempotencyKey): void;
}
