<?php

use App\Enums\ProviderStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Provider;
use App\Models\Refund;
use App\Models\Subscription;
use App\Services\Settings\SettingsService;

beforeEach(function () {
    app(SettingsService::class)->set('stripe.connect_webhook_secret', 'whsec_connect');
});

function stripeSub(Subscription $subscription, array $overrides = []): array
{
    return array_merge([
        'id' => $subscription->stripe_subscription_id, 'object' => 'subscription', 'customer' => 'cus_1', 'status' => 'active',
        'cancel_at_period_end' => false, 'current_period_start' => 1700000000, 'current_period_end' => 1702592000,
        'trial_end' => null, 'canceled_at' => null, 'ended_at' => null, 'pause_collection' => null,
        'items' => ['data' => [['price' => ['id' => $subscription->price->stripe_price_id]]]],
    ], $overrides);
}

it('FR-C2 customer.subscription.updated activates a paid subscription and mirrors its period', function () {
    $subscription = pendingSubscription();

    connectEvent('customer.subscription.updated', stripeSub($subscription), 1700000100)->assertOk();

    $subscription->refresh();
    expect($subscription->status->value)->toBe('active')
        ->and($subscription->current_period_start->timestamp)->toBe(1700000000)
        ->and($subscription->current_period_end->timestamp)->toBe(1702592000)
        ->and($subscription->stripe_event_at)->toBe(1700000100);
});

it('FR-C2 mirrors Stripe statuses onto ours', function (array $stripe, string $expected) {
    $subscription = pendingSubscription();

    connectEvent('customer.subscription.updated', stripeSub($subscription, $stripe), 1700000100)->assertOk();

    expect($subscription->fresh()->status->value)->toBe($expected);
})->with([
    'trialing' => [['status' => 'trialing', 'trial_end' => 1701000000], 'trialing'],
    'active' => [['status' => 'active'], 'active'],
    'past due' => [['status' => 'past_due'], 'past_due'],
    'unpaid counts as past due' => [['status' => 'unpaid'], 'past_due'],
    'incomplete' => [['status' => 'incomplete'], 'incomplete'],
    'incomplete expired' => [['status' => 'incomplete_expired'], 'expired'],
    'canceled' => [['status' => 'canceled', 'canceled_at' => 1700000050, 'ended_at' => 1700000050], 'canceled'],
    'collection paused' => [['status' => 'active', 'pause_collection' => ['behavior' => 'void']], 'paused'],
]);

it('FR-C2 a subscription in grace stays in grace while Stripe still says past due', function () {
    $subscription = pendingSubscription(['status' => 'grace']);

    connectEvent('customer.subscription.updated', stripeSub($subscription, ['status' => 'past_due']), 1700000100)->assertOk();

    expect($subscription->fresh()->status->value)->toBe('grace');
});

it('FR-PM4 a replayed event is processed once', function () {
    $subscription = pendingSubscription();
    $payload = stripeSub($subscription);

    connectEvent('customer.subscription.updated', $payload, 1700000100, 'evt_same')->assertOk()->assertJsonMissing(['duplicate' => true]);
    $subscription->update(['status' => 'past_due']); // something changed locally since
    connectEvent('customer.subscription.updated', $payload, 1700000100, 'evt_same')->assertOk()->assertJsonPath('data.duplicate', true);

    expect($subscription->fresh()->status->value)->toBe('past_due'); // the duplicate did not re-apply
});

it('FR-PM4 an older event arriving late never overwrites newer state', function () {
    $subscription = pendingSubscription();

    connectEvent('customer.subscription.updated', stripeSub($subscription, ['status' => 'past_due']), 1700000500)->assertOk();
    connectEvent('customer.subscription.updated', stripeSub($subscription, ['status' => 'active']), 1700000100)->assertOk();

    expect($subscription->fresh()->status->value)->toBe('past_due')->and($subscription->fresh()->stripe_event_at)->toBe(1700000500);
});

it('FR-C4 mirrors cancel-at-period-end and the final cancellation', function () {
    $subscription = pendingSubscription(['status' => 'active']);

    connectEvent('customer.subscription.updated', stripeSub($subscription, ['cancel_at_period_end' => true, 'canceled_at' => 1700000200]), 1700000200)->assertOk();
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue()->and($subscription->fresh()->status->value)->toBe('active');

    connectEvent('customer.subscription.deleted', stripeSub($subscription, ['status' => 'canceled', 'canceled_at' => 1700000200, 'ended_at' => 1702592000]), 1702592000)->assertOk();
    $fresh = $subscription->fresh();
    expect($fresh->status->value)->toBe('canceled')->and($fresh->ended_at->timestamp)->toBe(1702592000);
});

it('FR-C3 a plan change made in Stripe moves the local price and amount', function () {
    $subscription = pendingSubscription(['status' => 'active']);
    $bigger = $subscription->plan->prices()->create(['provider_id' => $subscription->provider_id, 'amount' => 5000, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_bigger']);

    $payload = stripeSub($subscription);
    $payload['items']['data'][0]['price']['id'] = 'price_bigger';
    connectEvent('customer.subscription.updated', $payload, 1700000100)->assertOk();

    expect($subscription->fresh()->price_id)->toBe($bigger->id)->and($subscription->fresh()->amount)->toBe(5000);
});

it('FR-PM3 ignores events for subscriptions or accounts we do not know', function () {
    $subscription = pendingSubscription();

    connectEvent('customer.subscription.updated', ['id' => 'sub_unknown', 'status' => 'active', 'items' => ['data' => []]], 1700000100)->assertOk();
    connectEvent('customer.subscription.updated', stripeSub($subscription), 1700000100, account: 'acct_nobody')->assertOk();

    expect($subscription->fresh()->status->value)->toBe('incomplete');
});

it('FR-MT an event from another provider\'s account cannot touch this provider\'s subscription', function () {
    $subscription = pendingSubscription();
    Provider::factory()->create(['slug' => 'rival', 'status' => ProviderStatus::Active, 'stripe_account_id' => 'acct_rival']);

    connectEvent('customer.subscription.updated', stripeSub($subscription), 1700000100, account: 'acct_rival')->assertOk();

    expect($subscription->fresh()->status->value)->toBe('incomplete');
});

it('FR-C6 stores an invoice from its events and keeps it current', function () {
    $subscription = pendingSubscription();
    $raw = fn (array $o = []) => array_merge([
        'id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => $subscription->stripe_subscription_id,
        'number' => 'ACME-0001', 'status' => 'open', 'currency' => 'usd', 'amount_due' => 2000, 'amount_paid' => 0,
        'hosted_invoice_url' => 'https://invoice.stripe.test/i/1', 'invoice_pdf' => 'https://invoice.stripe.test/i/1.pdf',
        'period_start' => 1700000000, 'period_end' => 1702592000, 'due_date' => null, 'status_transitions' => ['paid_at' => null],
    ], $o);

    connectEvent('invoice.finalized', $raw(), 1700000100)->assertOk();
    $invoice = Invoice::sole();
    expect($invoice->status)->toBe('open')->and($invoice->amount_due)->toBe(2000)->and($invoice->subscription_id)->toBe($subscription->id)
        ->and($invoice->provider_customer_id)->toBe($subscription->provider_customer_id)->and($invoice->hosted_invoice_url)->toBe('https://invoice.stripe.test/i/1');

    connectEvent('invoice.paid', $raw(['status' => 'paid', 'amount_paid' => 2000, 'status_transitions' => ['paid_at' => 1700000200]]), 1700000200)->assertOk();
    expect(Invoice::count())->toBe(1)->and($invoice->fresh()->status)->toBe('paid')->and($invoice->fresh()->amount_paid)->toBe(2000)->and($invoice->fresh()->paid_at->timestamp)->toBe(1700000200);

    connectEvent('invoice.finalized', $raw(), 1700000100, 'evt_late')->assertOk(); // older event arrives last
    expect($invoice->fresh()->status)->toBe('paid');
});

it('FR-C6 a failed invoice payment is mirrored too', function () {
    $subscription = pendingSubscription(['status' => 'active']);

    connectEvent('invoice.payment_failed', [
        'id' => 'in_2', 'customer' => 'cus_1', 'subscription' => $subscription->stripe_subscription_id, 'status' => 'open',
        'currency' => 'usd', 'amount_due' => 2000, 'amount_paid' => 0, 'attempt_count' => 1,
    ], 1700000300)->assertOk();

    expect(Invoice::sole()->status)->toBe('open')->and(Invoice::sole()->amount_due)->toBe(2000);
});

it('FR-PM5 records a successful payment against its invoice', function () {
    $subscription = pendingSubscription();
    $invoice = Invoice::factory()->create(['provider_id' => $subscription->provider_id, 'subscription_id' => $subscription->id, 'stripe_invoice_id' => 'in_1', 'status' => 'open']);

    connectEvent('payment_intent.succeeded', [
        'id' => 'pi_1', 'object' => 'payment_intent', 'amount' => 2000, 'amount_received' => 2000, 'currency' => 'usd',
        'latest_charge' => 'ch_1', 'invoice' => 'in_1', 'status' => 'succeeded',
    ], 1700000200)->assertOk();

    $payment = Payment::sole();
    expect($payment->status)->toBe('succeeded')->and($payment->amount)->toBe(2000)->and($payment->stripe_charge_id)->toBe('ch_1')
        ->and($payment->invoice_id)->toBe($invoice->id)->and($payment->subscription_id)->toBe($subscription->id)->and($payment->paid_at)->not->toBeNull();
});

it('FR-PM5 records why a payment failed', function () {
    $subscription = pendingSubscription();
    Invoice::factory()->create(['provider_id' => $subscription->provider_id, 'subscription_id' => $subscription->id, 'stripe_invoice_id' => 'in_1', 'status' => 'open']);

    connectEvent('payment_intent.payment_failed', [
        'id' => 'pi_2', 'amount' => 2000, 'currency' => 'usd', 'invoice' => 'in_1', 'status' => 'requires_payment_method',
        'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.'],
    ], 1700000200)->assertOk();

    $payment = Payment::sole();
    expect($payment->status)->toBe('failed')->and($payment->failure_code)->toBe('insufficient_funds')->and($payment->failure_message)->toBe('Your card has insufficient funds.')->and($payment->paid_at)->toBeNull();
});

it('FR-C10 charge.refunded mirrors partial then full refunds and is safe to replay', function () {
    $subscription = pendingSubscription();
    $payment = Payment::factory()->create(['provider_id' => $subscription->provider_id, 'subscription_id' => $subscription->id, 'amount' => 2000, 'stripe_charge_id' => 'ch_1', 'stripe_payment_intent_id' => 'pi_1']);
    $charge = fn (int $refunded, array $refunds) => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 2000, 'amount_refunded' => $refunded, 'currency' => 'usd', 'refunds' => ['data' => $refunds]];

    connectEvent('charge.refunded', $charge(500, [['id' => 're_1', 'amount' => 500, 'currency' => 'usd', 'status' => 'succeeded', 'reason' => null]]), 1700000300)->assertOk();
    expect($payment->fresh()->amount_refunded)->toBe(500)->and($payment->fresh()->status)->toBe('partially_refunded')->and(Refund::count())->toBe(1);

    connectEvent('charge.refunded', $charge(500, [['id' => 're_1', 'amount' => 500, 'currency' => 'usd', 'status' => 'succeeded']]), 1700000300, 'evt_again')->assertOk();
    expect($payment->fresh()->amount_refunded)->toBe(500)->and(Refund::count())->toBe(1);

    connectEvent('charge.refunded', $charge(2000, [['id' => 're_2', 'amount' => 1500, 'currency' => 'usd', 'status' => 'succeeded'], ['id' => 're_1', 'amount' => 500, 'currency' => 'usd', 'status' => 'succeeded']]), 1700000400)->assertOk();
    expect($payment->fresh()->amount_refunded)->toBe(2000)->and($payment->fresh()->status)->toBe('refunded')->and(Refund::count())->toBe(2);
});

it('FR-C7 mirrors saved cards: attached, made default, detached', function () {
    $subscription = pendingSubscription();
    $card = fn (array $o = []) => array_merge(['id' => 'pm_1', 'object' => 'payment_method', 'customer' => 'cus_1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2031]], $o);

    connectEvent('payment_method.attached', $card(), 1700000100)->assertOk();
    $method = PaymentMethod::sole();
    expect($method->brand)->toBe('visa')->and($method->last4)->toBe('4242')->and($method->exp_year)->toBe(2031)
        ->and($method->provider_customer_id)->toBe($subscription->provider_customer_id)->and($method->is_default)->toBeFalse();

    connectEvent('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => 'pm_1']], 1700000200)->assertOk();
    expect($method->fresh()->is_default)->toBeTrue();

    connectEvent('payment_method.detached', $card(['customer' => null]), 1700000300)->assertOk();
    expect(PaymentMethod::count())->toBe(0)->and(PaymentMethod::withTrashed()->count())->toBe(1);
});
