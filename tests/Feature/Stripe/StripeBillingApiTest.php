<?php

use App\Services\Stripe\StripeApi;
use App\Services\Stripe\StripeSubscriptionState;

/** A stand-in for \Stripe\StripeClient: records `service.method` calls and returns canned objects. */
function billingSdk(array $returns = []): object
{
    return new class($returns)
    {
        public array $calls = [];

        public function __construct(public array $returns) {}

        public function __get(string $service): object
        {
            return new class($this, $service)
            {
                public function __construct(private object $root, private string $service) {}

                public function __call(string $method, array $args): mixed
                {
                    $this->root->calls[] = [$this->service.'.'.$method, ...$args];

                    return $this->root->returns[$this->service.'.'.$method] ?? (object) ['id' => 'x_1'];
                }
            };
        }
    };
}

function rawSubscription(array $overrides = []): array
{
    return array_merge([
        'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'incomplete', 'cancel_at_period_end' => false,
        'current_period_start' => 1700000000, 'current_period_end' => 1702592000, 'trial_end' => null,
        'canceled_at' => null, 'ended_at' => null, 'pause_collection' => null,
        'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_1']]]],
        'latest_invoice' => ['id' => 'in_1', 'confirmation_secret' => ['client_secret' => 'pi_1_secret_a']],
        'pending_setup_intent' => null,
    ], $overrides);
}

it('FR-C1 normalises a Stripe subscription into the facts we store', function () {
    $state = StripeSubscriptionState::normalize(rawSubscription());

    expect($state)->toMatchArray([
        'id' => 'sub_1', 'status' => 'incomplete', 'price_id' => 'price_1', 'item_id' => 'si_1', 'customer_id' => 'cus_1',
        'current_period_start' => 1700000000, 'current_period_end' => 1702592000, 'trial_end' => null,
        'cancel_at_period_end' => false, 'paused' => false, 'latest_invoice_id' => 'in_1',
        'client_secret' => 'pi_1_secret_a', 'client_secret_type' => 'payment',
    ]);
});

it('FR-C1 asks for a setup intent when a trial means nothing is due yet', function () {
    $state = StripeSubscriptionState::normalize(rawSubscription([
        'status' => 'trialing', 'trial_end' => 1701000000,
        'latest_invoice' => ['id' => 'in_1', 'confirmation_secret' => null],
        'pending_setup_intent' => ['id' => 'seti_1', 'client_secret' => 'seti_1_secret_b'],
    ]));

    expect($state)->toMatchArray(['client_secret' => 'seti_1_secret_b', 'client_secret_type' => 'setup', 'trial_end' => 1701000000]);
});

it('FR-C1 reads period dates from the item when Stripe moved them off the subscription', function () {
    $raw = rawSubscription();
    unset($raw['current_period_start'], $raw['current_period_end']);
    $raw['items']['data'][0] += ['current_period_start' => 1710000000, 'current_period_end' => 1712592000];

    expect(StripeSubscriptionState::normalize($raw))->toMatchArray(['current_period_start' => 1710000000, 'current_period_end' => 1712592000]);
});

it('FR-C4 flags a subscription whose collection is paused', function () {
    expect(StripeSubscriptionState::normalize(rawSubscription(['pause_collection' => ['behavior' => 'void']]))['paused'])->toBeTrue();
});

it('FR-C1 creates the customer and subscription ON the connected account with idempotency keys', function () {
    $sdk = billingSdk(['customers.create' => (object) ['id' => 'cus_1'], 'subscriptions.create' => rawSubscription()]);
    $api = new StripeApi(fn () => $sdk);

    expect($api->createCustomer('acct_1', ['email' => 'a@b.test'], 'pc-1-customer'))->toBe('cus_1')
        ->and($sdk->calls[0])->toBe(['customers.create', ['email' => 'a@b.test'], ['stripe_account' => 'acct_1', 'idempotency_key' => 'pc-1-customer']]);

    $state = $api->createSubscription('acct_1', ['customer' => 'cus_1'], 'sub-1-create');

    expect($state['id'])->toBe('sub_1')
        ->and($sdk->calls[1][0])->toBe('subscriptions.create')
        ->and($sdk->calls[1][1]['expand'])->toBe(['latest_invoice.confirmation_secret', 'pending_setup_intent'])
        ->and($sdk->calls[1][2])->toBe(['stripe_account' => 'acct_1', 'idempotency_key' => 'sub-1-create']);
});

it('FR-C4 updates and cancels subscriptions on the connected account', function () {
    $sdk = billingSdk(['subscriptions.update' => rawSubscription(['cancel_at_period_end' => true]), 'subscriptions.cancel' => rawSubscription(['status' => 'canceled'])]);
    $api = new StripeApi(fn () => $sdk);

    expect($api->updateSubscription('acct_1', 'sub_1', ['cancel_at_period_end' => true], 'sub-1-cancel-end')['cancel_at_period_end'])->toBeTrue()
        ->and($sdk->calls[0])->toBe(['subscriptions.update', 'sub_1', ['cancel_at_period_end' => true], ['stripe_account' => 'acct_1', 'idempotency_key' => 'sub-1-cancel-end']]);

    expect($api->cancelSubscription('acct_1', 'sub_1', 'sub-1-cancel-now')['status'])->toBe('canceled')
        ->and($sdk->calls[1])->toBe(['subscriptions.cancel', 'sub_1', null, ['stripe_account' => 'acct_1', 'idempotency_key' => 'sub-1-cancel-now']]);
});

it('FR-C10 refunds a payment intent on the connected account, in full or in part', function () {
    $sdk = billingSdk(['refunds.create' => (object) ['id' => 're_1', 'status' => 'succeeded']]);
    $api = new StripeApi(fn () => $sdk);

    expect($api->createRefund('acct_1', 'pi_1', 500, 'refund-1', 'requested_by_customer'))->toBe(['id' => 're_1', 'status' => 'succeeded'])
        ->and($sdk->calls[0])->toBe(['refunds.create', ['payment_intent' => 'pi_1', 'amount' => 500, 'reason' => 'requested_by_customer'], ['stripe_account' => 'acct_1', 'idempotency_key' => 'refund-1']]);

    $api->createRefund('acct_1', 'pi_1', null, 'refund-2', null);
    expect($sdk->calls[1][1])->toBe(['payment_intent' => 'pi_1']);
});

it('FR-C7 manages saved cards on the connected account', function () {
    $sdk = billingSdk(['setupIntents.create' => (object) ['id' => 'seti_1', 'client_secret' => 'seti_1_secret'], 'customers.update' => (object) ['id' => 'cus_1']]);
    $api = new StripeApi(fn () => $sdk);

    expect($api->createSetupIntent('acct_1', 'cus_1', 'pc-1-setup-1'))->toBe('seti_1_secret')
        ->and($sdk->calls[0])->toBe(['setupIntents.create', ['customer' => 'cus_1', 'usage' => 'off_session', 'automatic_payment_methods' => ['enabled' => true]], ['stripe_account' => 'acct_1', 'idempotency_key' => 'pc-1-setup-1']]);

    $api->setDefaultPaymentMethod('acct_1', 'cus_1', 'pm_1', 'pc-1-default-pm_1');
    expect($sdk->calls[1])->toBe(['customers.update', 'cus_1', ['invoice_settings' => ['default_payment_method' => 'pm_1']], ['stripe_account' => 'acct_1', 'idempotency_key' => 'pc-1-default-pm_1']]);

    $api->detachPaymentMethod('acct_1', 'pm_1', 'pm_1-detach');
    expect($sdk->calls[2])->toBe(['paymentMethods.detach', 'pm_1', null, ['stripe_account' => 'acct_1', 'idempotency_key' => 'pm_1-detach']]);
});

it('FR-PM6 lists a connected account\'s subscriptions for reconciliation', function () {
    $sdk = billingSdk(['subscriptions.all' => (object) ['data' => [rawSubscription(), rawSubscription(['id' => 'sub_2'])]]]);

    $subs = (new StripeApi(fn () => $sdk))->listSubscriptions('acct_1');

    expect(array_column($subs, 'id'))->toBe(['sub_1', 'sub_2'])
        ->and($sdk->calls[0])->toBe(['subscriptions.all', ['status' => 'all', 'limit' => 100], ['stripe_account' => 'acct_1']]);
});
