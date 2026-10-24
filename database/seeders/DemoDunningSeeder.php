<?php

namespace Database\Seeders;

use App\Enums\ProviderStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Dunning\DunningService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A provider with one subscriber whose renewal just failed on a soft decline, so the payment recovery screens have
 * something to show (provider overview and invoices, admin dashboard, the customer's fix-payment page). Nothing talks to
 * Stripe. Never run it on production.
 *
 *   php artisan db:seed --class=DemoDunningSeeder
 *
 * Logins (password Password123!): recovery-owner@example.com (provider), recovery-customer@example.com (customer).
 */
class DemoDunningSeeder extends Seeder
{
    public function run(DunningService $dunning): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo dunning data is for local development only.');
        }

        $this->call(RoleSeeder::class);

        $owner = $this->user('recovery-owner@example.com', 'Riley Owner')->assignRole('provider_owner');
        $customer = $this->user('recovery-customer@example.com', 'Robin Customer');

        $provider = Provider::updateOrCreate(['slug' => 'recovery-gym'], [
            'name' => 'Recovery Gym', 'status' => ProviderStatus::Active, 'business_category' => 'fitness', 'country' => 'US',
            'support_email' => 'help@recovery-gym.test', 'currency' => 'usd', 'timezone' => 'UTC', 'approved_at' => now(),
        ]);
        $provider->forceFill(['stripe_account_id' => 'acct_recovery', 'stripe_status' => 'enabled'])->save();
        $provider->members()->firstOrCreate(['user_id' => $owner->id], ['role' => 'owner']);

        $plan = Plan::updateOrCreate(['provider_id' => $provider->id, 'name' => 'Recovery Plus'], [
            'description' => 'Everything included', 'features' => [], 'status' => 'active', 'visibility' => 'public', 'trial_days' => 0, 'stripe_product_id' => 'prod_recovery',
        ]);
        $price = Price::updateOrCreate(['provider_id' => $provider->id, 'plan_id' => $plan->id, 'interval' => 'month'], [
            'amount' => 2500, 'currency' => 'usd', 'interval_count' => 1, 'status' => 'active', 'stripe_price_id' => 'price_recovery',
        ]);

        $link = ProviderCustomer::updateOrCreate(['provider_id' => $provider->id, 'user_id' => $customer->id], ['stripe_customer_id' => 'cus_recovery']);
        $subscription = Subscription::firstOrCreate(['stripe_subscription_id' => 'sub_recovery'], [
            'provider_id' => $provider->id, 'provider_customer_id' => $link->id, 'plan_id' => $plan->id, 'price_id' => $price->id,
            'status' => 'active', 'amount' => 2500, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1,
            'current_period_start' => now()->subDays(1), 'current_period_end' => now()->addDays(29),
        ]);
        $invoice = Invoice::firstOrCreate(['stripe_invoice_id' => 'in_recovery'], [
            'provider_id' => $provider->id, 'subscription_id' => $subscription->id, 'provider_customer_id' => $link->id, 'number' => 'RECOVERY-0001',
            'status' => 'open', 'currency' => 'usd', 'amount_due' => 2500, 'amount_paid' => 0, 'hosted_invoice_url' => 'https://invoice.stripe.test/recovery',
        ]);

        if (! $invoice->attempts()->exists()) {
            $dunning->recordFailure($invoice, 'insufficient_funds', 'Your card has insufficient funds.', 'pi_recovery_1');
        }
    }

    private function user(string $email, string $name): User
    {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $name, 'password' => DemoBillingSeeder::PASSWORD, 'email_verified_at' => now(), 'deleted_at' => null])->save();

        return $user->assignRole('customer');
    }
}
