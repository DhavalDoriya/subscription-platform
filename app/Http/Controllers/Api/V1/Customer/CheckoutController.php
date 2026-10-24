<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StartCheckoutRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Price;
use App\Services\Billing\CheckoutService;
use App\Services\Settings\SettingsService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Starts a subscription on the provider whose storefront the request came from (`tenant` middleware). */
class CheckoutController extends Controller
{
    public function __construct(private CheckoutService $checkout, private TenantContext $tenancy, private SettingsService $settings) {}

    public function store(StartCheckoutRequest $request): JsonResponse
    {
        $provider = $this->tenancy->current()
            ?? throw new ApiException(__('api.billing.storefront_required'), 422, 'storefront_required');

        // Tenant-scoped lookups: another provider's price, or a draft/archived one, is simply a 404.
        $price = Price::with('plan')
            ->where('status', 'active')->whereNotNull('stripe_price_id')
            ->whereHas('plan', fn ($plan) => $plan->where('status', 'active'))
            ->findOrFail($request->validated('price_id'));

        $started = $this->checkout->start($request->user('api'), $provider, $price);

        return ApiResponse::created([
            'subscription' => (new SubscriptionResource($started['subscription']->load('plan')))->resolve(),
            'client_secret' => $started['client_secret'],
            'client_secret_type' => $started['client_secret_type'],
            // Stripe.js must be created for the provider's connected account, or it cannot find the payment.
            'stripe_account' => $provider->stripe_account_id,
            'publishable_key' => $this->settings->get('stripe.publishable_key'),
        ]);
    }
}
