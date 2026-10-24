<?php

namespace Database\Seeders;

use App\Enums\ProviderStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Storefront\StorefrontService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A ready-made provider with a published storefront, one subscriber, an invoice, two payments and a saved card, for local
 * demos and the Playwright billing sweep. Nothing here talks to Stripe. Never run it on production.
 *
 *   php artisan db:seed --class=DemoBillingSeeder
 *
 * Logins (password Password123!): demo-owner@example.com (provider), demo-customer@example.com (customer).
 */
class DemoBillingSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    public function run(StorefrontService $storefront): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo billing data is for local development only.');
        }

        $this->call(RoleSeeder::class);

        $owner = $this->user('demo-owner@example.com', 'Dana Owner')->assignRole('provider_owner');
        $customer = $this->user('demo-customer@example.com', 'Casey Customer');

        $provider = Provider::updateOrCreate(['slug' => 'demo-gym'], [
            'name' => 'Demo Gym', 'status' => ProviderStatus::Active, 'business_category' => 'fitness', 'country' => 'US',
            'support_email' => 'help@demo-gym.test', 'currency' => 'usd', 'timezone' => 'UTC', 'approved_at' => now(),
        ]);
        $provider->forceFill(['stripe_account_id' => 'acct_demo', 'stripe_status' => 'enabled'])->save();
        $provider->members()->firstOrCreate(['user_id' => $owner->id], ['role' => 'owner']);

        $plan = Plan::updateOrCreate(['provider_id' => $provider->id, 'name' => 'Demo Pro'], [
            'description' => 'Everything included', 'features' => ['Unlimited classes', 'Priority support'],
            'status' => 'active', 'visibility' => 'public', 'trial_days' => 0, 'stripe_product_id' => 'prod_demo',
        ]);
        $price = Price::updateOrCreate(['provider_id' => $provider->id, 'plan_id' => $plan->id, 'interval' => 'month'], [
            'amount' => 1900, 'currency' => 'usd', 'interval_count' => 1, 'status' => 'active', 'stripe_price_id' => 'price_demo',
        ]);

        $storefront->save($provider, ['title' => 'Demo Gym'], [
            ['type' => 'hero', 'data' => ['headline' => 'Welcome to Demo Gym', 'subheadline' => 'Train smarter']],
            ['type' => 'plans', 'data' => ['heading' => 'Pricing']],
        ]);
        $storefront->publish($provider, $owner);

        $link = ProviderCustomer::updateOrCreate(['provider_id' => $provider->id, 'user_id' => $customer->id], ['stripe_customer_id' => 'cus_demo']);
        $subscription = Subscription::updateOrCreate(['stripe_subscription_id' => 'sub_demo'], [
            'provider_id' => $provider->id, 'provider_customer_id' => $link->id, 'plan_id' => $plan->id, 'price_id' => $price->id,
            'status' => 'active', 'amount' => 1900, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1,
            'current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20),
        ]);
        Invoice::updateOrCreate(['stripe_invoice_id' => 'in_demo'], [
            'provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $link->id, 'number' => 'DEMO-0001',
            'status' => 'paid', 'currency' => 'usd', 'amount_due' => 1900, 'amount_paid' => 1900, 'paid_at' => now()->subDays(10),
            'hosted_invoice_url' => 'https://invoice.stripe.test/demo', 'invoice_pdf_url' => 'https://invoice.stripe.test/demo.pdf',
        ]);
        Payment::updateOrCreate(['stripe_payment_intent_id' => 'pi_demo_ok'], [
            'provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'status' => 'succeeded', 'amount' => 1900,
            'amount_refunded' => 0, 'currency' => 'usd', 'paid_at' => now()->subDays(10), 'stripe_charge_id' => 'ch_demo_ok',
        ]);
        Payment::updateOrCreate(['stripe_payment_intent_id' => 'pi_demo_failed'], [
            'provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'status' => 'failed', 'amount' => 1900,
            'amount_refunded' => 0, 'currency' => 'usd', 'failure_code' => 'insufficient_funds', 'failure_message' => 'Your card has insufficient funds.',
        ]);
        PaymentMethod::updateOrCreate(['stripe_payment_method_id' => 'pm_demo'], [
            'provider_id' => $provider->id, 'provider_customer_id' => $link->id, 'brand' => 'visa', 'last4' => '4242',
            'exp_month' => 12, 'exp_year' => (int) now()->addYears(3)->format('Y'), 'is_default' => true,
        ]);
    }

    private function user(string $email, string $name): User
    {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now(), 'deleted_at' => null])->save();

        return $user->assignRole('customer');
    }
}
