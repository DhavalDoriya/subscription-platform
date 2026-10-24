<?php

use App\Enums\ProviderStatus;
use App\Enums\SubscriptionStatus;
use App\Models\DunningEvent;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Price;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Dunning\DunningService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    app(SettingsService::class)->set('stripe.connect_webhook_secret', 'whsec_connect');
});
afterEach(fn () => Carbon::setTestNow());

/** The signed-in provider with a subscriber whose invoice failed on a soft decline. @return array{User, App\Models\Provider, Invoice, Subscription, User} */
function providerWithFailedInvoice(): array
{
    [$owner, $provider] = providerOwnerSession(['slug' => 'acme', 'status' => ProviderStatus::Active, 'stripe_account_id' => 'acct_acme', 'stripe_status' => 'enabled']);
    $plan = Plan::factory()->create(['provider_id' => $provider->id, 'name' => 'Gold', 'status' => 'active', 'stripe_product_id' => 'prod_api']);
    $price = Price::factory()->create(['provider_id' => $provider->id, 'plan_id' => $plan->id, 'amount' => 2000, 'currency' => 'usd', 'stripe_price_id' => 'price_api']);
    $customer = User::factory()->create(['name' => 'Casey Customer']);
    $subscription = subscribe($customer, $provider, $plan, $price, ['stripe_subscription_id' => 'sub_api1']);
    $invoice = Invoice::factory()->create([
        'provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $subscription->provider_customer_id,
        'stripe_invoice_id' => 'in_api1', 'status' => 'open', 'amount_due' => 2000, 'amount_paid' => 0, 'paid_at' => null,
    ]);
    app(DunningService::class)->recordFailure($invoice, 'insufficient_funds', 'Declined.', 'pi_api1');

    return [$owner, $provider, $invoice->fresh(), $subscription, $customer];
}

it('FR-R12 the provider lists failed invoices with their dunning state', function () {
    [, , $invoice] = providerWithFailedInvoice();
    Invoice::factory()->create(['provider_id' => $invoice->provider_id, 'status' => 'paid']);

    $response = $this->getJson('/api/v1/provider/invoices?dunning=active')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0'))->toMatchArray(['id' => $invoice->id, 'dunning_status' => 'retrying', 'next_retry_at' => '2026-11-03T09:00:00+00:00', 'first_failed_at' => '2026-11-02T09:00:00+00:00']);
});

it('FR-R4 the provider sees every attempt and notice of an invoice', function () {
    [, , $invoice] = providerWithFailedInvoice();

    $data = $this->getJson("/api/v1/provider/invoices/{$invoice->id}/dunning")->assertOk()->json('data');

    expect($data['attempts'])->toHaveCount(1)
        ->and($data['attempts'][0])->toMatchArray(['attempt_number' => 1, 'status' => 'failed', 'decline_code' => 'insufficient_funds', 'decline_type' => 'soft', 'kind' => 'initial'])
        ->and(array_column($data['events'], 'type'))->toBe(['failure_notice', 'retry_scheduled']);
});

it('FR-R9 the provider retries a failed invoice now', function () {
    [, , $invoice] = providerWithFailedInvoice();

    $this->postJson("/api/v1/provider/invoices/{$invoice->id}/retry")
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.dunning_status', 'recovered');

    expect(fakeStripe()->callsTo('payInvoice'))->toHaveCount(1);
});

it('FR-R9 a manual retry that fails is recorded and reported, not an error', function () {
    [, , $invoice] = providerWithFailedInvoice();
    fakeStripe()->declineInvoice('in_api1', 'insufficient_funds', 'pi_api2');

    $this->postJson("/api/v1/provider/invoices/{$invoice->id}/retry")
        ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.dunning_status', 'retrying');

    expect($invoice->fresh()->attempts)->toHaveCount(2)->and($invoice->fresh()->attempts->last()->kind)->toBe('manual');
});

it('FR-R9 a paid invoice cannot be retried', function () {
    [, , $invoice] = providerWithFailedInvoice();
    $invoice->forceFill(['status' => 'paid'])->save();

    $this->postJson("/api/v1/provider/invoices/{$invoice->id}/retry")->assertStatus(409)->assertJsonPath('code', 'not_retryable');
    expect(fakeStripe()->callsTo('payInvoice'))->toBeEmpty();
});

it('FR-R9 the provider marks an invoice resolved', function () {
    [, , $invoice] = providerWithFailedInvoice();

    $this->postJson("/api/v1/provider/invoices/{$invoice->id}/resolve")->assertOk()->assertJsonPath('data.dunning_status', 'resolved');

    expect($invoice->fresh()->next_retry_at)->toBeNull();
});

it('FR-MT a provider cannot touch another providers invoice', function () {
    providerWithFailedInvoice();
    $foreign = Invoice::factory()->create(['dunning_status' => 'retrying', 'status' => 'open']);

    $this->postJson("/api/v1/provider/invoices/{$foreign->id}/retry")->assertNotFound();
    $this->postJson("/api/v1/provider/invoices/{$foreign->id}/resolve")->assertNotFound();
    $this->getJson("/api/v1/provider/invoices/{$foreign->id}/dunning")->assertNotFound();
});

it('FR-R9 an admin retries or resolves any invoice', function () {
    [, , $invoice] = providerWithFailedInvoice();
    adminSession();

    $this->postJson("/api/v1/admin/invoices/{$invoice->id}/retry")->assertOk()->assertJsonPath('data.dunning_status', 'recovered');

    [, , $second] = [null, null, Invoice::factory()->create(['dunning_status' => 'retrying', 'status' => 'open'])];
    $this->postJson("/api/v1/admin/invoices/{$second->id}/resolve")->assertOk()->assertJsonPath('data.dunning_status', 'resolved');
});

it('FR-R9 the retry and resolve actions are closed to customers', function () {
    [, , $invoice] = providerWithFailedInvoice();
    asGuest();
    customerSession();

    $this->postJson("/api/v1/admin/invoices/{$invoice->id}/retry")->assertForbidden();
});

/** A customer with a failed invoice. @return array{User, ProviderCustomer, Invoice, Subscription} */
function customerWithFailedInvoice(string $code = 'insufficient_funds'): array
{
    [$acme, $plan, $price] = liveStorefront();
    $me = customerSession();
    $subscription = subscribe($me, $acme, $plan, $price, ['stripe_subscription_id' => 'sub_me1']);
    $link = $subscription->providerCustomer;
    $link->update(['stripe_customer_id' => 'cus_me']);
    $invoice = Invoice::factory()->create([
        'provider_id' => $acme->id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $link->id,
        'stripe_invoice_id' => 'in_me1', 'status' => 'open', 'amount_due' => 2000, 'amount_paid' => 0, 'paid_at' => null,
    ]);
    app(DunningService::class)->recordFailure($invoice, $code, 'Declined.', 'pi_me1');

    return [$me, $link, $invoice->fresh(), $subscription->fresh()];
}

it('FR-R3 the customer sees what is wrong with their payment and how long they have', function () {
    [, , $invoice, $subscription] = customerWithFailedInvoice('stolen_card');
    $stranger = User::factory()->create();
    subscribe($stranger, $subscription->provider, $subscription->plan, $subscription->price, ['stripe_subscription_id' => 'sub_other']);

    $data = $this->getJson('/api/v1/customer/payment-issues')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->toMatchArray([
            'invoice_id' => $invoice->id, 'subscription_id' => $subscription->id, 'amount_due' => 2000, 'currency' => 'usd',
            'dunning_status' => 'awaiting_customer', 'decline_type' => 'hard', 'subscription_status' => 'grace',
            'grace_ends_at' => '2026-11-09T09:00:00+00:00', 'next_retry_at' => null,
        ])
        ->and($data[0]['plan']['name'])->not->toBeEmpty()
        ->and($data[0]['provider']['name'])->not->toBeEmpty();
});

it('FR-R3 there is nothing to show a customer whose payments are fine', function () {
    customerSession();

    $this->getJson('/api/v1/customer/payment-issues')->assertOk()->assertJsonPath('data', []);
});

it('FR-R8 choosing a new default card retries the open invoice straight away', function () {
    [$me, $link, $invoice] = customerWithFailedInvoice('stolen_card');
    $card = PaymentMethod::factory()->create(['provider_id' => $link->provider_id, 'provider_customer_id' => $link->id, 'last4' => '4242']);

    $this->postJson("/api/v1/customer/payment-methods/{$card->id}/default")->assertOk();

    $invoice->refresh();
    expect($invoice->status)->toBe('paid')->and($invoice->dunning_status)->toBe('recovered')
        ->and($invoice->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->attempts->last()->kind)->toBe('card_update')
        ->and(fakeStripe()->callsTo('payInvoice'))->toHaveCount(1);
});

it('FR-R8 a card change with nothing failing does not charge anything', function () {
    [$acme] = liveStorefront();
    $me = customerSession();
    $link = ProviderCustomer::factory()->create(['provider_id' => $acme->id, 'user_id' => $me->id, 'stripe_customer_id' => 'cus_me']);
    $card = PaymentMethod::factory()->create(['provider_id' => $acme->id, 'provider_customer_id' => $link->id]);

    $this->postJson("/api/v1/customer/payment-methods/{$card->id}/default")->assertOk();

    expect(fakeStripe()->callsTo('payInvoice'))->toBeEmpty();
});

it('FR-R8 a default card changed on Stripe retries too, but only when it really changed', function () {
    [, $link] = customerWithFailedInvoice('stolen_card');
    $old = PaymentMethod::factory()->create(['provider_id' => $link->provider_id, 'provider_customer_id' => $link->id, 'is_default' => true, 'stripe_payment_method_id' => 'pm_old']);
    PaymentMethod::factory()->create(['provider_id' => $link->provider_id, 'provider_customer_id' => $link->id, 'stripe_payment_method_id' => 'pm_new']);

    connectEvent('customer.updated', ['id' => 'cus_me', 'invoice_settings' => ['default_payment_method' => 'pm_old']], 1700000200)->assertOk();
    expect(fakeStripe()->callsTo('payInvoice'))->toBeEmpty();

    connectEvent('customer.updated', ['id' => 'cus_me', 'invoice_settings' => ['default_payment_method' => 'pm_new']], 1700000300)->assertOk();
    expect(fakeStripe()->callsTo('payInvoice'))->toHaveCount(1);
});

it('FR-R12 the provider overview reports failed payments, recovery and the revenue at risk', function () {
    [, $provider, $invoice, $subscription] = providerWithFailedInvoice();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    // One more recovered and one lost invoice in the last 30 days.
    $dunning = app(DunningService::class);
    $recovered = Invoice::factory()->create(['provider_id' => $provider->id, 'status' => 'open', 'dunning_status' => 'retrying']);
    $dunning->markRecovered($recovered);
    $lost = Invoice::factory()->create(['provider_id' => $provider->id, 'status' => 'open', 'dunning_status' => 'exhausted']);
    DunningEvent::factory()->create(['provider_id' => $provider->id, 'invoice_id' => $lost->id, 'type' => 'canceled', 'occurred_at' => now()]);

    $dunningMetrics = $this->getJson('/api/v1/provider/overview')->assertOk()->json('data.dunning');

    expect($dunningMetrics)->toMatchArray(['open_invoices' => 2, 'open_amount' => 3900, 'at_risk_mrr' => 2000, 'recovered_30d' => 1, 'lost_30d' => 1, 'recovery_rate' => 50]);
});

it('FR-R12 recovery rate is empty until something has been recovered or lost', function () {
    providerOwnerSession();

    $this->getJson('/api/v1/provider/overview')->assertOk()->assertJsonPath('data.dunning.recovery_rate', null)->assertJsonPath('data.dunning.open_invoices', 0);
});

it('FR-R12 the admin dashboard reports the same across all providers', function () {
    providerWithFailedInvoice();
    adminSession();

    $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.dunning');

    expect($data)->toMatchArray(['open_invoices' => 1, 'recovered_30d' => 0, 'lost_30d' => 0, 'recovery_rate' => null])
        ->and($data['open_amount'])->toBe(['usd' => 2000])
        ->and($data['at_risk_mrr'])->toBe(['usd' => 2000]);
});
