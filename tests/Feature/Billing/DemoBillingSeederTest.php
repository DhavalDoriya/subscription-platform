<?php

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Dunning\DunningService;
use App\Services\Storefront\StorefrontService;
use Database\Seeders\DemoBillingSeeder;
use Database\Seeders\DemoDunningSeeder;

it('PRD 9 seeds a demo provider with a live storefront and billing history, and is safe to run twice', function () {
    $this->seed(DemoBillingSeeder::class);
    $this->seed(DemoBillingSeeder::class);

    expect(Subscription::count())->toBe(1);

    $this->getJson('http://demo-gym.platform.test/api/v1/public/storefront')
        ->assertOk()->assertJsonPath('data.published', true)->assertJsonPath('data.plans.0.name', 'Demo Pro');
    loginAs(User::where('email', 'demo-customer@example.com')->first())->assertOk();
});

it('PRD 9 refuses to seed demo data on production', function () {
    $this->app->detectEnvironment(fn () => 'production');

    app(DemoBillingSeeder::class)->run(app(StorefrontService::class));
})->throws(RuntimeException::class);

it('FR-R12 seeds a subscriber whose renewal failed, ready for the recovery screens, and is safe to run twice', function () {
    $this->seed(DemoDunningSeeder::class);
    $this->seed(DemoDunningSeeder::class);

    $invoice = Invoice::sole();
    expect($invoice->dunning_status)->toBe('retrying')
        ->and($invoice->attempts)->toHaveCount(1)
        ->and(Subscription::sole()->status)->toBe(SubscriptionStatus::PastDue);
    loginAs(User::where('email', 'recovery-owner@example.com')->first())->assertOk();
});

it('FR-R12 refuses to seed the dunning demo on production', function () {
    $this->app->detectEnvironment(fn () => 'production');

    app(DemoDunningSeeder::class)->run(app(DunningService::class));
})->throws(RuntimeException::class);
