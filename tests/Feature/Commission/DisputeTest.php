<?php

use App\Models\Commission;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Provider;
use App\Services\Settings\SettingsService;

beforeEach(function () {
    app(SettingsService::class)->set('stripe.connect_webhook_secret', 'whsec_connect');
});

function disputeObject(array $overrides = []): array
{
    return array_merge([
        'id' => 'dp_1', 'charge' => 'ch_c1', 'payment_intent' => 'pi_c1', 'amount' => 2000, 'currency' => 'usd', 'reason' => 'fraudulent',
        'status' => 'needs_response', 'evidence_details' => ['due_by' => 1700500000],
    ], $overrides);
}

it('FR-CM3 records a dispute against the payment when it is opened', function () {
    soldAt('10.00');
    paymentSucceeded();

    connectEvent('charge.dispute.created', disputeObject(), 1700000300)->assertOk();

    $dispute = Dispute::sole();
    expect($dispute->status)->toBe('needs_response')
        ->and($dispute->amount)->toBe(2000)
        ->and($dispute->reason)->toBe('fraudulent')
        ->and($dispute->payment_id)->toBe(Payment::sole()->id)
        ->and($dispute->evidence_due_by->timestamp)->toBe(1700500000)
        ->and(Commission::sole()->commission_refunded)->toBe(0); // nothing is lost yet
});

it('FR-CM3 a replayed or older dispute event changes nothing', function () {
    soldAt('10.00');
    paymentSucceeded();
    connectEvent('charge.dispute.created', disputeObject(), 1700000300)->assertOk();
    connectEvent('charge.dispute.closed', disputeObject(['status' => 'won']), 1700000900)->assertOk();

    connectEvent('charge.dispute.updated', disputeObject(['status' => 'under_review']), 1700000500)->assertOk(); // older than the close

    expect(Dispute::count())->toBe(1)->and(Dispute::sole()->status)->toBe('won');
});

it('FR-CM3 a lost dispute gives the platform fee back and asks Stripe to refund it', function () {
    soldAt('10.00');
    paymentSucceeded();
    connectEvent('charge.dispute.created', disputeObject(), 1700000300)->assertOk();

    connectEvent('charge.dispute.closed', disputeObject(['status' => 'lost']), 1700000900)->assertOk();

    $commission = Commission::sole();
    expect(Dispute::sole()->status)->toBe('lost')
        ->and($commission->commission_refunded)->toBe(200)
        ->and($commission->netCommission())->toBe(0)
        ->and($commission->providerNet())->toBe(0) // the provider lost the money
        ->and($commission->dispute_lost_at)->not->toBeNull();

    $call = fakeStripe()->callsTo('refundApplicationFeeForCharge')[0];
    expect($call['charge'])->toBe('ch_c1')->and($call['idempotency_key'])->toContain('dp_1');
});

it('FR-CM3 a won dispute keeps the fee and asks Stripe for nothing', function () {
    soldAt('10.00');
    paymentSucceeded();

    connectEvent('charge.dispute.created', disputeObject(), 1700000300)->assertOk();
    connectEvent('charge.dispute.closed', disputeObject(['status' => 'won']), 1700000900)->assertOk();

    expect(Commission::sole()->netCommission())->toBe(200)
        ->and(fakeStripe()->callsTo('refundApplicationFeeForCharge'))->toBe([]);
});

it('FR-CM3 a lost dispute after a partial refund returns only the fee that is left', function () {
    soldAt('10.00');
    paymentSucceeded();
    connectEvent('charge.refunded', ['id' => 'ch_c1', 'payment_intent' => 'pi_c1', 'amount_refunded' => 500, 'refunds' => ['data' => []]], 1700000300)->assertOk();

    connectEvent('charge.dispute.closed', disputeObject(['status' => 'lost', 'amount' => 1500]), 1700000900)->assertOk();

    expect(Commission::sole()->commission_refunded)->toBe(200)->and(Commission::sole()->netCommission())->toBe(0);
});

it('FR-CM3 a payment without a fee still records the dispute', function () {
    soldAt('0.00');
    paymentSucceeded();

    connectEvent('charge.dispute.closed', disputeObject(['status' => 'lost']), 1700000900)->assertOk();

    expect(Dispute::sole()->status)->toBe('lost')->and(fakeStripe()->callsTo('refundApplicationFeeForCharge'))->toBe([]);
});

it('FR-CM3 a dispute for a payment we do not know is ignored', function () {
    soldAt('10.00');

    connectEvent('charge.dispute.created', disputeObject(['charge' => 'ch_unknown', 'payment_intent' => 'pi_unknown']), 1700000300)->assertOk();

    expect(Dispute::count())->toBe(0);
});

it('FR-CM4 the provider payments list shows the dispute on the payment', function () {
    [, $provider, $payment] = providerWithPayment();
    Dispute::factory()->create(['provider_id' => $provider->id, 'payment_id' => $payment->id, 'status' => 'needs_response', 'amount' => 1900]);

    $row = collect($this->getJson('/api/v1/provider/payments')->assertOk()->json('data'))->firstWhere('id', $payment->id);

    expect($row['dispute'])->toMatchArray(['status' => 'needs_response', 'amount' => 1900]);
});

it('FR-CM3 isolates disputes per provider', function () {
    assertTenantIsolation(Dispute::class, fn (Provider $p) => Dispute::factory()->create(['provider_id' => $p->id]));
});
