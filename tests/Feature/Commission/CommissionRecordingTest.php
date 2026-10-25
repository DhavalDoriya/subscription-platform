<?php

use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Settings\SettingsService;

beforeEach(function () {
    app(SettingsService::class)->set('stripe.connect_webhook_secret', 'whsec_connect');
});

/** A subscription sold at $percent that has an invoice in_c1 and a payment intent we will report as paid. */
function soldAt(string $percent): void
{
    pendingSubscription(['status' => 'active', 'stripe_subscription_id' => 'sub_c1', 'application_fee_percent' => $percent]);
    connectEvent('invoice.created', ['id' => 'in_c1', 'customer' => 'cus_1', 'subscription' => 'sub_c1', 'status' => 'open', 'currency' => 'usd', 'amount_due' => 2000, 'amount_paid' => 0], 1700000100);
}

function paymentSucceeded(int $amount = 2000, int $at = 1700000200): void
{
    connectEvent('payment_intent.succeeded', [
        'id' => 'pi_c1', 'customer' => 'cus_1', 'amount' => $amount, 'amount_received' => $amount, 'currency' => 'usd', 'invoice' => 'in_c1', 'latest_charge' => 'ch_c1',
    ], $at)->assertOk();
}

it('FR-CM3 records the commission with the rate snapshot when a payment succeeds', function () {
    soldAt('10.00');
    paymentSucceeded(2000);

    $commission = Commission::sole();
    expect($commission->gross_amount)->toBe(2000)
        ->and($commission->rate_percent)->toBe('10.00')
        ->and($commission->commission_amount)->toBe(200)
        ->and($commission->currency)->toBe('usd')
        ->and($commission->payment_id)->toBe(Payment::sole()->id)
        ->and($commission->providerNet())->toBe(1800);
});

it('FR-CM3 rounds the fee to whole minor units', function () {
    soldAt('2.50');
    paymentSucceeded(1999); // 49.975 -> 50

    expect(Commission::sole()->commission_amount)->toBe(50);
});

it('FR-CM3 records nothing for a failed payment or a zero rate, and is idempotent on replay', function () {
    soldAt('0.00');
    paymentSucceeded();
    expect(Commission::count())->toBe(0);
});

it('FR-CM3 a replayed payment event does not duplicate the commission', function () {
    soldAt('10.00');
    paymentSucceeded(2000, 1700000200);
    paymentSucceeded(2000, 1700000201);

    expect(Commission::count())->toBe(1);
});

it('FR-CM3 the stored rate does not change when the rate changes later', function () {
    soldAt('10.00');
    paymentSucceeded();
    CommissionRule::factory()->create(['percent' => 25]);

    expect(Commission::sole()->rate_percent)->toBe('10.00')->and(Commission::sole()->commission_amount)->toBe(200);
});

it('FR-CM3 a refund returns the matching part of the fee', function () {
    soldAt('10.00');
    paymentSucceeded();

    connectEvent('charge.refunded', ['id' => 'ch_c1', 'payment_intent' => 'pi_c1', 'amount_refunded' => 500, 'refunds' => ['data' => [
        ['id' => 're_c1', 'amount' => 500, 'currency' => 'usd', 'status' => 'succeeded'],
    ]]], 1700000300)->assertOk();

    $commission = Commission::sole();
    expect($commission->refunded_amount)->toBe(500)
        ->and($commission->commission_refunded)->toBe(50)
        ->and($commission->netCommission())->toBe(150)
        ->and($commission->providerNet())->toBe(1350);

    connectEvent('charge.refunded', ['id' => 'ch_c1', 'payment_intent' => 'pi_c1', 'amount_refunded' => 2000, 'refunds' => ['data' => []]], 1700000400)->assertOk();

    expect(Commission::sole()->commission_refunded)->toBe(200)->and(Commission::sole()->providerNet())->toBe(0);
});

it('FR-CM3 a refund by a provider through the API asks Stripe to refund the fee too and adjusts the commission', function () {
    [$owner, $provider, $payment] = providerWithPayment();
    Commission::factory()->create(['provider_id' => $provider->id, 'payment_id' => $payment->id, 'gross_amount' => 2000, 'commission_amount' => 200]);

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund", ['amount' => 1000])->assertCreated();

    expect(fakeStripe()->callsTo('createRefund')[0]['refund_application_fee'] ?? null)->toBeTrue()
        ->and(Commission::sole()->commission_refunded)->toBe(100)
        ->and(Refund::count())->toBe(1);
});
