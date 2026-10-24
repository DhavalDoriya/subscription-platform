<?php

use App\Models\Activity;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\Refund;
use App\Models\User;
use App\Services\Stripe\StripeGateway;
use Tests\Support\FakeStripeGateway;

/** A provider owner signed in, with a paid payment of 2000 for one of their subscribers. Returns [owner, provider, payment]. */
function providerWithPayment(array $payment = []): array
{
    [$owner, $provider] = providerOwnerSession(['slug' => 'acme', 'stripe_account_id' => 'acct_acme', 'stripe_status' => 'enabled']);
    $plan = Plan::factory()->create(['provider_id' => $provider->id, 'status' => 'active']);
    $price = $plan->prices()->create(['provider_id' => $provider->id, 'amount' => 2000, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1']);
    $subscription = subscribe(User::factory()->create(), $provider, $plan, $price);

    return [$owner, $provider, Payment::factory()->create(array_merge(['provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'amount' => 2000], $payment))];
}

it('FR-C10 a provider refunds a whole payment', function () {
    [$owner, , $payment] = providerWithPayment();

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund", ['reason' => 'requested_by_customer'])
        ->assertCreated()
        ->assertJsonPath('data.amount', 2000)
        ->assertJsonPath('data.status', 'succeeded');

    $call = fakeStripe()->callsTo('createRefund')[0];
    expect($call['account'])->toBe('acct_acme')->and($call['payment_intent'])->toBe($payment->stripe_payment_intent_id)
        ->and($call['amount'])->toBe(2000)->and($call['reason'])->toBe('requested_by_customer')->and($call['idempotency_key'])->toContain(Refund::sole()->id);

    expect($payment->fresh()->amount_refunded)->toBe(2000)->and($payment->fresh()->status)->toBe('refunded')
        ->and(Refund::sole()->created_by)->toBe($owner->id);
    expect(Activity::where('description', 'refund.created')->exists())->toBeTrue();
});

it('FR-C10 refunds part of a payment, then the rest, but never more than was paid', function () {
    [, , $payment] = providerWithPayment();

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund", ['amount' => 500])->assertCreated();
    expect($payment->fresh()->amount_refunded)->toBe(500)->and($payment->fresh()->status)->toBe('partially_refunded');

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund", ['amount' => 1600])->assertStatus(422)->assertJsonPath('code', 'refund_exceeds');
    expect(fakeStripe()->callsTo('createRefund'))->toHaveCount(1);

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund")->assertCreated()->assertJsonPath('data.amount', 1500);
    expect($payment->fresh()->amount_refunded)->toBe(2000)->and($payment->fresh()->status)->toBe('refunded');

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund")->assertStatus(409)->assertJsonPath('code', 'not_refundable'); // nothing left
});

it('FR-C10 validates the refund amount and reason', function (array $body, string $field) {
    [, , $payment] = providerWithPayment();

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund", $body)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'zero' => [['amount' => 0], 'amount'],
    'negative' => [['amount' => -5], 'amount'],
    'not a number' => [['amount' => 'ten'], 'amount'],
    'unknown reason' => [['reason' => 'because'], 'reason'],
]);

it('FR-C10 only a successful payment can be refunded', function (string $status) {
    [, , $payment] = providerWithPayment(['status' => $status]);

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund")->assertStatus(409)->assertJsonPath('code', 'not_refundable');
    expect(Refund::count())->toBe(0)->and(fakeStripe()->callsTo('createRefund'))->toBe([]);
})->with(['failed', 'pending', 'refunded']);

it('FR-MT a provider cannot refund another provider\'s payment', function () {
    providerWithPayment();
    $foreign = Payment::factory()->create(['provider_id' => Provider::factory()->create()->id]);

    $this->postJson("/api/v1/provider/payments/{$foreign->id}/refund")->assertNotFound();
    expect($foreign->fresh()->amount_refunded)->toBe(0);
});

it('FR-C10 leaves nothing recorded when Stripe refuses the refund', function () {
    [, , $payment] = providerWithPayment();
    $this->app->instance(StripeGateway::class, new class extends FakeStripeGateway
    {
        public function createRefund(string $accountId, string $paymentIntentId, ?int $amount, string $idempotencyKey, ?string $reason = null): array
        {
            throw new RuntimeException('charge_already_refunded');
        }
    });

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund")->assertStatus(500);

    expect(Refund::count())->toBe(0)->and($payment->fresh()->amount_refunded)->toBe(0);
});

it('FR-AD6 an admin can refund any payment, and is recorded as the actor', function () {
    [, , $payment] = providerWithPayment();
    $admin = User::factory()->create(['password' => 'Password123!'])->assignRole('admin');
    $this->withToken(loginAs($admin)->json('data.access_token'));
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", ['amount' => 700])->assertCreated()->assertJsonPath('data.amount', 700);

    expect(Refund::sole()->created_by)->toBe($admin->id)->and($payment->fresh()->amount_refunded)->toBe(700);
});

it('FR-C10 customers cannot refund anything', function () {
    [, , $payment] = providerWithPayment();
    customerSession();

    $this->postJson("/api/v1/provider/payments/{$payment->id}/refund")->assertStatus(404);
    $this->postJson("/api/v1/admin/payments/{$payment->id}/refund")->assertForbidden();
    expect(Refund::count())->toBe(0);
});
