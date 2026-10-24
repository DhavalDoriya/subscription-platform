<?php

namespace App\Services\Plans;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeGateway;

/**
 * Plan and price rules (PRD 4.4). Drafts live only in our database; going active creates the product and prices on the
 * provider's connected account. Stripe calls carry idempotency keys derived from our ids, so a retry never duplicates.
 */
class PlanService
{
    public function __construct(private StripeGateway $stripe, private SettingsService $settings) {}

    /** @param  array<string, mixed>  $data */
    public function create(Provider $provider, array $data): Plan
    {
        $live = Plan::where('provider_id', $provider->id)->where('status', '!=', 'archived')->count();

        if ($live >= $this->settings->get('provider.max_plans')) {
            throw $this->conflict('plan_limit_reached');
        }

        return Plan::create([
            'provider_id' => $provider->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'features' => $data['features'] ?? [],
            'trial_days' => $data['trial_days'] ?? 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'visibility' => $data['visibility'] ?? 'public',
            'status' => 'draft',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Provider $provider, Plan $plan, array $data): Plan
    {
        $plan->fill($data);
        $productChanged = $plan->isDirty(['name', 'description']);
        $plan->save();

        if ($productChanged && $plan->stripe_product_id) {
            $params = $this->productParams($plan, withMetadata: false);
            $this->stripe->updateProduct($provider->stripe_account_id, $plan->stripe_product_id, $params, "plan-{$plan->id}-update-".md5(json_encode($params)));
        }

        return $plan;
    }

    public function publish(Provider $provider, Plan $plan): Plan
    {
        match (true) {
            $plan->status === 'archived' => throw $this->conflict('plan_archived'),
            $plan->status === 'active' => throw $this->conflict('plan_already_active'),
            ! $provider->status->isOperational() => throw $this->conflict('provider_not_approved'),
            $provider->stripe_status !== 'enabled' => throw $this->conflict('stripe_not_enabled'),
            $plan->activePrices()->doesntExist() => throw $this->conflict('plan_needs_price'),
            default => null,
        };

        $this->ensureProduct($provider, $plan);

        foreach ($plan->activePrices()->whereNull('stripe_price_id')->get() as $price) {
            $this->syncPrice($provider, $plan, $price);
        }

        $plan->update(['status' => 'active']);

        return $plan;
    }

    public function archive(Provider $provider, Plan $plan): Plan
    {
        if ($plan->status === 'archived') {
            return $plan;
        }

        $plan->update(['status' => 'archived']);

        if ($plan->stripe_product_id) {
            $this->stripe->updateProduct($provider->stripe_account_id, $plan->stripe_product_id, ['active' => false], "plan-{$plan->id}-archive");
        }

        return $plan;
    }

    public function delete(Plan $plan): void
    {
        if ($plan->status !== 'draft' || $plan->stripe_product_id) {
            throw $this->conflict('plan_in_use'); // anything that was ever live is archived, never removed
        }

        $plan->delete();
    }

    /** @param  array<string, mixed>  $data */
    public function addPrice(Provider $provider, Plan $plan, array $data): Price
    {
        $this->assertEditable($plan);
        $this->assertSyncable($provider, $plan);

        $price = Price::create($this->priceAttributes($provider, $plan, $data));

        return $this->syncIfLive($provider, $plan, $price);
    }

    /**
     * A price that exists on Stripe is never edited (Stripe forbids it, and subscribers rely on it): we create the
     * replacement first, then archive the old one, so there is never a moment with no price.
     *
     * @param  array<string, mixed>  $data
     */
    public function changePrice(Provider $provider, Plan $plan, Price $price, array $data): Price
    {
        $this->assertEditable($plan);

        if ($price->status === 'archived') {
            throw $this->conflict('price_archived');
        }

        if (! $price->stripe_price_id) {
            $price->update(array_intersect_key($data, array_flip(['amount', 'interval', 'interval_count'])));

            return $price;
        }

        $this->assertSyncable($provider, $plan);

        $replacement = Price::create($this->priceAttributes($provider, $plan, $data + $price->only(['amount', 'interval', 'interval_count', 'currency'])));
        $this->syncPrice($provider, $plan, $replacement);
        $this->archivePriceRow($provider, $price);

        return $replacement;
    }

    public function removePrice(Provider $provider, Plan $plan, Price $price): void
    {
        if ($plan->status === 'active' && $plan->activePrices()->where('id', '!=', $price->id)->doesntExist()) {
            throw $this->conflict('plan_needs_price');
        }

        if ($price->stripe_price_id) {
            $this->archivePriceRow($provider, $price);

            return;
        }

        $price->delete();
    }

    private function ensureProduct(Provider $provider, Plan $plan): void
    {
        if ($plan->stripe_product_id) {
            return;
        }

        $id = $this->stripe->createProduct($provider->stripe_account_id, $this->productParams($plan), "plan-{$plan->id}-product");
        $plan->update(['stripe_product_id' => $id]);
    }

    private function syncPrice(Provider $provider, Plan $plan, Price $price): void
    {
        $params = [
            'product' => $plan->stripe_product_id,
            'unit_amount' => $price->amount,
            'currency' => $price->currency,
            'recurring' => ['interval' => $price->interval, 'interval_count' => $price->interval_count],
            'metadata' => ['price_id' => $price->id, 'plan_id' => $plan->id, 'provider_id' => $provider->id],
        ];

        // Stripe Tax needs to know whether the amount excludes tax; it is on only if the platform and the provider both opted in.
        if ($this->settings->get('stripe.tax_enabled') && ($provider->settings['tax']['enabled'] ?? false)) {
            $params['tax_behavior'] = 'exclusive';
        }

        $id = $this->stripe->createPrice($provider->stripe_account_id, $params, "price-{$price->id}");

        $price->update(['stripe_price_id' => $id]);
    }

    private function syncIfLive(Provider $provider, Plan $plan, Price $price): Price
    {
        if ($plan->status === 'active') {
            $this->syncPrice($provider, $plan, $price);
        }

        return $price;
    }

    private function archivePriceRow(Provider $provider, Price $price): void
    {
        $this->stripe->archivePrice($provider->stripe_account_id, $price->stripe_price_id, "price-{$price->id}-archive");
        $price->update(['status' => 'archived']);
    }

    /** An active plan's prices go straight to Stripe, so Stripe must still be usable. */
    private function assertSyncable(Provider $provider, Plan $plan): void
    {
        if ($plan->status === 'active' && $provider->stripe_status !== 'enabled') {
            throw $this->conflict('stripe_not_enabled');
        }
    }

    private function assertEditable(Plan $plan): void
    {
        if ($plan->status === 'archived') {
            throw $this->conflict('plan_archived');
        }
    }

    /** @return array<string, mixed> */
    private function priceAttributes(Provider $provider, Plan $plan, array $data): array
    {
        return [
            'provider_id' => $provider->id,
            'plan_id' => $plan->id,
            'amount' => $data['amount'],
            'currency' => $provider->currency,
            'interval' => $data['interval'],
            'interval_count' => $data['interval_count'] ?? 1,
            'status' => 'active',
        ];
    }

    /** @return array<string, mixed> */
    private function productParams(Plan $plan, bool $withMetadata = true): array
    {
        return array_filter([
            'name' => $plan->name,
            'description' => $plan->description ?: null,
            'metadata' => $withMetadata ? ['plan_id' => $plan->id, 'provider_id' => $plan->provider_id] : null,
        ], fn ($v) => $v !== null);
    }

    private function conflict(string $code): ApiException
    {
        return new ApiException(__("api.plans.{$code}"), 409, $code);
    }
}
