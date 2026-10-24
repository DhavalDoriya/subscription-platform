<?php

use App\Enums\DeclineType;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Settings\SettingsService;

beforeEach(function () {
    app(SettingsService::class)->set('stripe.connect_webhook_secret', 'whsec_connect');
});

function stripeInvoiceObject(array $overrides = []): array
{
    return array_merge([
        'id' => 'in_w1', 'customer' => 'cus_1', 'subscription' => 'sub_w1', 'status' => 'open', 'currency' => 'usd',
        'amount_due' => 2000, 'amount_paid' => 0, 'attempt_count' => 1,
    ], $overrides);
}

function failedPaymentIntent(array $overrides = []): array
{
    return array_merge([
        'id' => 'pi_w1', 'object' => 'payment_intent', 'customer' => 'cus_1', 'amount' => 2000, 'currency' => 'usd', 'invoice' => 'in_w1', 'status' => 'requires_payment_method',
        'last_payment_error' => ['decline_code' => 'insufficient_funds', 'code' => 'card_declined', 'message' => 'Your card has insufficient funds.'],
    ], $overrides);
}

it('FR-R1 a failed invoice payment switches off Stripe retries for that invoice only', function () {
    pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_w1']);

    connectEvent('invoice.payment_failed', stripeInvoiceObject(), 1700000300)->assertOk();

    $calls = fakeStripe()->callsTo('updateInvoice');
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['invoice'])->toBe('in_w1')
        ->and($calls[0]['params'])->toBe(['auto_advance' => false])
        ->and($calls[0]['account'])->toBe('acct_acme')
        ->and($calls[0]['idempotency_key'])->toBe('invoice-no-advance-'.Invoice::sole()->id);
});

it('FR-R1 a new invoice is left to Stripe, so renewals still finalise and charge', function () {
    pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_w1']);

    connectEvent('invoice.created', stripeInvoiceObject(['status' => 'draft']), 1700000100)->assertOk();

    expect(fakeStripe()->callsTo('updateInvoice'))->toBeEmpty();
});

it('FR-R4 a failed invoice payment starts the dunning with the decline Stripe reported', function () {
    $subscription = pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_w1']);
    connectEvent('payment_intent.payment_failed', failedPaymentIntent(), 1700000200)->assertOk();
    connectEvent('invoice.payment_failed', stripeInvoiceObject(), 1700000300)->assertOk();

    $attempt = PaymentAttempt::sole();
    $invoice = Invoice::sole();
    expect($attempt->decline_code)->toBe('insufficient_funds')
        ->and($attempt->decline_type)->toBe(DeclineType::Soft)
        ->and($attempt->stripe_payment_intent_id)->toBe('pi_w1')
        ->and($invoice->dunning_status)->toBe('retrying')
        ->and($invoice->next_retry_at)->not->toBeNull()
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('FR-R4 replaying the same webhook does not record the failure twice', function () {
    pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_w1']);

    connectEvent('invoice.payment_failed', stripeInvoiceObject(), 1700000300, id: 'evt_same')->assertOk();
    connectEvent('invoice.payment_failed', stripeInvoiceObject(), 1700000300, id: 'evt_same')->assertOk();

    expect(PaymentAttempt::count())->toBe(1)->and(fakeStripe()->callsTo('updateInvoice'))->toHaveCount(1);
});

it('FR-R3 a decline that arrives after the invoice event still sets the right course', function () {
    pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_w1']);
    connectEvent('invoice.payment_failed', stripeInvoiceObject(), 1700000300)->assertOk();
    expect(Invoice::sole()->dunning_status)->toBe('retrying');

    connectEvent('payment_intent.payment_failed', failedPaymentIntent(['last_payment_error' => ['decline_code' => 'stolen_card', 'message' => 'Stolen.']]), 1700000310)->assertOk();

    $attempt = PaymentAttempt::sole();
    $invoice = Invoice::sole();
    expect($attempt->decline_code)->toBe('stolen_card')
        ->and($attempt->decline_type)->toBe(DeclineType::Hard)
        ->and($attempt->next_retry_at)->toBeNull()
        ->and($attempt->stripe_payment_intent_id)->toBe('pi_w1')
        ->and($invoice->dunning_status)->toBe('awaiting_customer')
        ->and($invoice->next_retry_at)->toBeNull()
        ->and($invoice->subscription->status)->toBe(SubscriptionStatus::Grace);
});

it('FR-R6 payment on a failed invoice recovers it', function () {
    $subscription = pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_w1']);
    connectEvent('invoice.payment_failed', stripeInvoiceObject(), 1700000300)->assertOk();

    connectEvent('invoice.paid', stripeInvoiceObject(['status' => 'paid', 'amount_paid' => 2000, 'status_transitions' => ['paid_at' => 1700000900]]), 1700000900)->assertOk();

    $invoice = Invoice::sole();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->dunning_status)->toBe('recovered')
        ->and($invoice->next_retry_at)->toBeNull()
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('FR-R1 a first payment that fails during checkout is not dunned', function () {
    pendingSubscription(['status' => 'incomplete', 'stripe_subscription_id' => 'sub_w1']);

    connectEvent('invoice.payment_failed', stripeInvoiceObject(), 1700000300)->assertOk();

    expect(PaymentAttempt::count())->toBe(0)->and(fakeStripe()->callsTo('updateInvoice'))->toBeEmpty()->and(Invoice::sole()->dunning_status)->toBeNull();
});

it('FR-R1 an invoice of a customer we never linked is ignored', function () {
    connectEvent('invoice.payment_failed', stripeInvoiceObject(['customer' => 'cus_unknown', 'subscription' => 'sub_unknown']), 1700000300)->assertOk();

    expect(Invoice::count())->toBe(0)->and(PaymentAttempt::count())->toBe(0)->and(Payment::count())->toBe(0);
});
