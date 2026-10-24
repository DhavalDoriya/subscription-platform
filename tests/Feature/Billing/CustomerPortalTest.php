<?php

use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\User;

it('FR-CP1 lists my subscriptions across providers, not other people\'s, and hides abandoned checkouts', function () {
    [$acme, $plan, $price] = liveStorefront();
    $me = customerSession();
    subscribe($me, $acme, $plan, $price);
    subscribe($me, $acme, Plan::factory()->create(['provider_id' => $acme->id, 'status' => 'active']), Price::factory()->create(['provider_id' => $acme->id, 'plan_id' => Plan::factory()->create(['provider_id' => $acme->id])->id]), ['status' => 'incomplete']);
    subscribe($me, $acme, $plan, $price, ['status' => 'expired']);
    subscribe(User::factory()->create(), $acme, $plan, $price);

    $other = Provider::factory()->create(['slug' => 'other', 'name' => 'Other Co']);
    $otherPlan = Plan::factory()->create(['provider_id' => $other->id, 'name' => 'Basic']);
    subscribe($me, $other, $otherPlan, Price::factory()->create(['provider_id' => $other->id, 'plan_id' => $otherPlan->id]));

    $response = $this->getJson('/api/v1/customer/subscriptions')->assertOk()->assertJsonPath('meta.total', 2);

    expect(collect($response->json('data'))->pluck('provider.slug')->sort()->values()->all())->toBe(['acme', 'other']);
});

it('FR-CP1 requires a login', function () {
    $this->getJson('/api/v1/customer/subscriptions')->assertUnauthorized();
    $this->getJson('/api/v1/customer/invoices')->assertUnauthorized();
    $this->getJson('/api/v1/customer/payment-methods')->assertUnauthorized();
});

it('FR-CP2 lists my billing history with download links, newest first', function () {
    [$acme, $plan, $price] = liveStorefront();
    $me = customerSession();
    $subscription = subscribe($me, $acme, $plan, $price);
    $old = Invoice::factory()->create(['provider_id' => $acme->id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $subscription->provider_customer_id, 'number' => 'A-1', 'created_at' => now()->subMonth()]);
    $new = Invoice::factory()->create(['provider_id' => $acme->id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $subscription->provider_customer_id, 'number' => 'A-2', 'hosted_invoice_url' => 'https://pay.stripe.test/i/2', 'invoice_pdf_url' => 'https://pay.stripe.test/i/2.pdf']);
    $stranger = subscribe(User::factory()->create(), $acme, $plan, $price);
    Invoice::factory()->create(['provider_id' => $acme->id, 'subscription_id' => $stranger->id, 'provider_customer_id' => $stranger->provider_customer_id, 'number' => 'X-9']);

    $response = $this->getJson('/api/v1/customer/invoices')->assertOk()->assertJsonPath('meta.total', 2);

    expect(array_column($response->json('data'), 'number'))->toBe(['A-2', 'A-1'])
        ->and($response->json('data.0'))->toMatchArray(['hosted_invoice_url' => 'https://pay.stripe.test/i/2', 'invoice_pdf_url' => 'https://pay.stripe.test/i/2.pdf', 'provider' => ['id' => $acme->id, 'name' => $acme->name, 'slug' => 'acme']]);
    expect($response->getContent())->not->toContain('X-9');
});

/** The signed-in customer with a link to acme holding two saved cards. Returns [user, provider, link, first, second]. */
function customerWithCards(): array
{
    [$acme] = liveStorefront();
    $me = customerSession();
    $link = ProviderCustomer::factory()->create(['provider_id' => $acme->id, 'user_id' => $me->id, 'stripe_customer_id' => 'cus_me']);
    $first = PaymentMethod::factory()->create(['provider_id' => $acme->id, 'provider_customer_id' => $link->id, 'last4' => '4242', 'is_default' => true]);
    $second = PaymentMethod::factory()->create(['provider_id' => $acme->id, 'provider_customer_id' => $link->id, 'last4' => '1111']);

    return [$me, $acme, $link, $first, $second];
}

it('FR-C7 lists only my saved cards, with no sensitive data', function () {
    [, $acme, , $first] = customerWithCards();
    $stranger = ProviderCustomer::factory()->create(['provider_id' => $acme->id]);
    PaymentMethod::factory()->create(['provider_id' => $acme->id, 'provider_customer_id' => $stranger->id, 'last4' => '9999']);

    $response = $this->getJson('/api/v1/customer/payment-methods')->assertOk();

    expect($response->json('data'))->toHaveCount(2)->and($response->json('data.0'))->toHaveKeys(['id', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'provider'])
        ->and($response->json('data.0'))->not->toHaveKey('stripe_payment_method_id');
    expect($response->getContent())->not->toContain('9999');
});

it('FR-C7 makes another card the default', function () {
    [, $acme, , $first, $second] = customerWithCards();

    $this->postJson("/api/v1/customer/payment-methods/{$second->id}/default")->assertOk()->assertJsonPath('data.is_default', true);

    expect($second->fresh()->is_default)->toBeTrue()->and($first->fresh()->is_default)->toBeFalse();
    $call = fakeStripe()->callsTo('setDefaultPaymentMethod')[0];
    expect($call)->toMatchArray(['account' => 'acct_acme', 'customer' => 'cus_me', 'payment_method' => $second->stripe_payment_method_id]);
});

it('FR-C7 removes a card, but not the last one under a live subscription', function () {
    [$me, $acme, $link, $first, $second] = customerWithCards();
    subscribe($me, $acme, Plan::first(), Price::first());

    $this->deleteJson("/api/v1/customer/payment-methods/{$second->id}")->assertNoContent();
    expect(PaymentMethod::find($second->id))->toBeNull()->and(fakeStripe()->callsTo('detachPaymentMethod')[0]['payment_method'])->toBe($second->stripe_payment_method_id);

    $this->deleteJson("/api/v1/customer/payment-methods/{$first->id}")->assertStatus(409)->assertJsonPath('code', 'payment_method_in_use');
    expect(PaymentMethod::find($first->id))->not->toBeNull();
});

it('FR-C7 may remove the last card when nothing is subscribed', function () {
    [, , , $first, $second] = customerWithCards();
    $second->delete();

    $this->deleteJson("/api/v1/customer/payment-methods/{$first->id}")->assertNoContent();
});

it('FR-MT I cannot change or remove someone else\'s card', function () {
    [, $acme] = customerWithCards();
    $stranger = ProviderCustomer::factory()->create(['provider_id' => $acme->id, 'stripe_customer_id' => 'cus_other']);
    $theirs = PaymentMethod::factory()->create(['provider_id' => $acme->id, 'provider_customer_id' => $stranger->id]);

    $this->postJson("/api/v1/customer/payment-methods/{$theirs->id}/default")->assertNotFound();
    $this->deleteJson("/api/v1/customer/payment-methods/{$theirs->id}")->assertNotFound();
    expect(fakeStripe()->callsTo('detachPaymentMethod'))->toBe([]);
});

it('FR-C7 starts adding a card for a provider I already subscribed to', function () {
    [, $acme] = customerWithCards();

    $response = $this->postJson('/api/v1/customer/payment-methods/setup-intent', ['provider_id' => $acme->id])->assertOk();

    expect($response->json('data.stripe_account'))->toBe('acct_acme');
    expect($response->json('data.client_secret'))->toStartWith('seti_')->and(fakeStripe()->callsTo('createSetupIntent')[0])->toMatchArray(['account' => 'acct_acme', 'customer' => 'cus_me']);
});

it('FR-C7 cannot add a card for a provider I never subscribed to', function () {
    customerWithCards();
    $stranger = Provider::factory()->create(['slug' => 'nope', 'stripe_account_id' => 'acct_nope']);

    $this->postJson('/api/v1/customer/payment-methods/setup-intent', ['provider_id' => $stranger->id])->assertNotFound();
    expect(fakeStripe()->callsTo('createSetupIntent'))->toBe([]);
});

it('FR-C3 lists the other prices I could switch to: same provider and currency, live plans only', function () {
    [$acme, $plan, $price] = liveStorefront();
    $me = customerSession();
    $subscription = subscribe($me, $acme, $plan, $price);
    $max = Plan::factory()->create(['provider_id' => $acme->id, 'status' => 'active', 'name' => 'Max']);
    $maxPrice = $max->prices()->create(['provider_id' => $acme->id, 'amount' => 5000, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_max']);
    $max->prices()->create(['provider_id' => $acme->id, 'amount' => 4000, 'currency' => 'eur', 'interval' => 'month', 'stripe_price_id' => 'price_eur']);
    $max->prices()->create(['provider_id' => $acme->id, 'amount' => 3000, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_old', 'status' => 'archived']);
    Plan::factory()->create(['provider_id' => $acme->id, 'status' => 'draft'])->prices()->create(['provider_id' => $acme->id, 'amount' => 100, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_draft']);
    $other = Provider::factory()->create(['slug' => 'other']);
    Plan::factory()->create(['provider_id' => $other->id, 'status' => 'active'])->prices()->create(['provider_id' => $other->id, 'amount' => 100, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_other']);

    $response = $this->getJson("/api/v1/customer/subscriptions/{$subscription->id}/options")->assertOk();

    expect(array_column($response->json('data'), 'price_id'))->toBe([$maxPrice->id])
        ->and($response->json('data.0'))->toMatchArray(['plan_name' => 'Max', 'amount' => 5000, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1]);
});

it('FR-MT I cannot see switch options for someone else\'s subscription', function () {
    [$acme, $plan, $price] = liveStorefront();
    customerSession();
    $theirs = subscribe(User::factory()->create(), $acme, $plan, $price);

    $this->getJson("/api/v1/customer/subscriptions/{$theirs->id}/options")->assertNotFound();
});
