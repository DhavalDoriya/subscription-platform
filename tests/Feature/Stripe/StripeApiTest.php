<?php

use App\Exceptions\ApiException;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeAccountState;
use App\Services\Stripe\StripeApi;
use Stripe\StripeClient;

/** A stand-in for \Stripe\StripeClient that records what the wrapper sends. */
function sdkRecorder(): object
{
    return new class
    {
        public array $calls = [];

        public object $accounts;

        public object $accountLinks;

        public object $products;

        public object $prices;

        public function __construct()
        {
            $self = $this;
            $this->accounts = new class($self)
            {
                public function __construct(private object $root) {}

                public function create(array $params, array $opts = []): object
                {
                    $this->root->calls[] = ['accounts.create', $params, $opts];

                    return (object) ['id' => 'acct_1', 'details_submitted' => false, 'charges_enabled' => false, 'payouts_enabled' => false, 'requirements' => (object) ['disabled_reason' => null, 'currently_due' => ['external_account']]];
                }

                public function retrieve(string $id, ?array $params = null, array $opts = []): object
                {
                    $this->root->calls[] = ['accounts.retrieve', $id];

                    return (object) ['id' => $id, 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true, 'requirements' => (object) ['disabled_reason' => null, 'currently_due' => []]];
                }

                public function createLoginLink(string $id, ?array $params = null, array $opts = []): object
                {
                    $this->root->calls[] = ['accounts.createLoginLink', $id];

                    return (object) ['url' => 'https://dashboard.stripe.com/express/x'];
                }
            };
            $recorder = fn (string $name, array $returns) => new class($self, $name, $returns)
            {
                public function __construct(private object $root, private string $name, private array $returns) {}

                public function create(array $params, array $opts = []): object
                {
                    $this->root->calls[] = [$this->name.'.create', $params, $opts];

                    return (object) $this->returns;
                }

                public function update(string $id, array $params, array $opts = []): object
                {
                    $this->root->calls[] = [$this->name.'.update', $id, $params, $opts];

                    return (object) $this->returns;
                }
            };
            $this->products = $recorder('products', ['id' => 'prod_1']);
            $this->prices = $recorder('prices', ['id' => 'price_1']);
            $this->accountLinks = new class($self)
            {
                public function __construct(private object $root) {}

                public function create(array $params, array $opts = []): object
                {
                    $this->root->calls[] = ['accountLinks.create', $params, $opts];

                    return (object) ['url' => 'https://connect.stripe.com/setup/x'];
                }
            };
        }
    };
}

it('FR-PM7 creates accounts with an idempotency key and returns the normalised state', function () {
    $sdk = sdkRecorder();
    $state = (new StripeApi(fn () => $sdk))->createExpressAccount(['type' => 'express', 'country' => 'US'], 'provider-1-account');

    expect($sdk->calls[0][0])->toBe('accounts.create')
        ->and($sdk->calls[0][1])->toBe(['type' => 'express', 'country' => 'US'])
        ->and($sdk->calls[0][2])->toBe(['idempotency_key' => 'provider-1-account'])
        ->and($state)->toMatchArray(['id' => 'acct_1', 'details_submitted' => false, 'charges_enabled' => false, 'currently_due' => ['external_account']]);
});

it('FR-PM1 builds an onboarding link for the connected account and a dashboard login link', function () {
    $sdk = sdkRecorder();
    $api = new StripeApi(fn () => $sdk);

    expect($api->createAccountLink('acct_1', 'https://app.test/refresh', 'https://app.test/return'))->toBe('https://connect.stripe.com/setup/x')
        ->and($sdk->calls[0][1])->toBe(['account' => 'acct_1', 'refresh_url' => 'https://app.test/refresh', 'return_url' => 'https://app.test/return', 'type' => 'account_onboarding'])
        ->and($api->createLoginLink('acct_1'))->toBe('https://dashboard.stripe.com/express/x');
});

it('FR-P4 maps Stripe account facts to our four onboarding states', function (array $account, string $status) {
    expect(StripeAccountState::status(StripeAccountState::normalize($account)))->toBe($status);
})->with([
    'details not submitted' => [['id' => 'a', 'details_submitted' => false], 'pending'],
    'submitted and fully enabled' => [['id' => 'a', 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true], 'enabled'],
    'submitted but charges disabled' => [['id' => 'a', 'details_submitted' => true, 'charges_enabled' => false, 'payouts_enabled' => true, 'requirements' => ['disabled_reason' => 'requirements.past_due']], 'restricted'],
    'submitted but payouts disabled' => [['id' => 'a', 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => false], 'restricted'],
]);

it('FR-AD7 refuses to call Stripe until a secret key is configured', function () {
    (new StripeApi(StripeApi::clientFromSettings(...)))->retrieveAccount('acct_1');
})->throws(ApiException::class);

it('FR-AD7 builds a client from the encrypted secret key once it is set', function () {
    app(SettingsService::class)->set('stripe.secret_key', 'sk_test_abc');

    expect(StripeApi::clientFromSettings())->toBeInstanceOf(StripeClient::class);
});

it('FR-PL4 creates products and prices ON the connected account, each with an idempotency key', function () {
    $sdk = sdkRecorder();
    $api = new StripeApi(fn () => $sdk);

    expect($api->createProduct('acct_1', ['name' => 'Pro'], 'plan-1-product'))->toBe('prod_1')
        ->and($sdk->calls[0])->toBe(['products.create', ['name' => 'Pro'], ['stripe_account' => 'acct_1', 'idempotency_key' => 'plan-1-product']]);

    expect($api->createPrice('acct_1', ['unit_amount' => 900], 'price-1'))->toBe('price_1')
        ->and($sdk->calls[1])->toBe(['prices.create', ['unit_amount' => 900], ['stripe_account' => 'acct_1', 'idempotency_key' => 'price-1']]);
});

it('FR-PL4 updates products and archives prices on the connected account', function () {
    $sdk = sdkRecorder();
    $api = new StripeApi(fn () => $sdk);

    $api->updateProduct('acct_1', 'prod_1', ['name' => 'Renamed'], 'plan-1-update-1');
    $api->archivePrice('acct_1', 'price_1', 'price-1-archive');

    expect($sdk->calls[0])->toBe(['products.update', 'prod_1', ['name' => 'Renamed'], ['stripe_account' => 'acct_1', 'idempotency_key' => 'plan-1-update-1']])
        ->and($sdk->calls[1])->toBe(['prices.update', 'price_1', ['active' => false], ['stripe_account' => 'acct_1', 'idempotency_key' => 'price-1-archive']]);
});
