<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\Providers\CurrentProvider;
use App\Services\Stripe\ProviderStripeSync;
use App\Services\Stripe\StripeGateway;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeConnectController extends Controller
{
    public function __construct(private StripeGateway $stripe, private CurrentProvider $current, private ProviderStripeSync $sync) {}

    /** Creates the Express account on first use, then returns Stripe's hosted onboarding link. Return URLs are ours, never the client's. */
    public function onboarding(Request $request): JsonResponse
    {
        $provider = $this->current->for($request->user('api'));

        if (! $provider->status->isOperational()) {
            throw new ApiException(__('api.stripe.provider_not_approved'), 409, 'provider_not_approved');
        }

        if (! $provider->stripe_account_id) {
            $state = $this->stripe->createExpressAccount($this->accountParams($provider), "provider-{$provider->id}-express-account");
            $provider->forceFill(['stripe_account_id' => $state['id']])->save();
            $this->sync->apply($provider, $state, $request->user('api'));
        }

        $frontend = rtrim(config('app.frontend_url'), '/');

        return ApiResponse::success([
            'url' => $this->stripe->createAccountLink($provider->stripe_account_id, $frontend.'/dashboard/stripe/refresh', $frontend.'/dashboard/stripe/return'),
        ]);
    }

    /** Stored status by default; `?refresh=1` asks Stripe first (used when the provider returns from onboarding). */
    public function show(Request $request): JsonResponse
    {
        $provider = $this->current->for($request->user('api'));
        $data = [];

        if ($request->boolean('refresh') && $provider->stripe_account_id) {
            $state = $this->stripe->retrieveAccount($provider->stripe_account_id);
            $this->sync->apply($provider, $state, $request->user('api'));
            $data = array_diff_key($state, ['id' => 1, 'disabled_reason' => 1]);
        }

        return ApiResponse::success([
            'status' => $provider->fresh()->stripe_status,
            'connected' => $provider->stripe_account_id !== null,
        ] + $data);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $provider = $this->current->for($request->user('api'));

        if (! $provider->stripe_account_id || ! in_array($provider->stripe_status, ['enabled', 'restricted'], true)) {
            throw new ApiException(__('api.stripe.not_connected'), 409, 'stripe_not_connected');
        }

        // Standard accounts own their dashboard and have no login link.
        $url = $this->accountType() === 'standard' ? 'https://dashboard.stripe.com' : $this->stripe->createLoginLink($provider->stripe_account_id);

        return ApiResponse::success(['url' => $url]);
    }

    /** @return array<string, mixed> */
    private function accountParams(Provider $provider): array
    {
        $type = $this->accountType();

        return [
            'type' => $type,
            'country' => $provider->country,
            'email' => $provider->support_email,
            'business_profile' => ['name' => $provider->name, 'support_email' => $provider->support_email],
            'metadata' => ['provider_id' => $provider->id],
        ] + ($type === 'express' ? ['capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]]] : []);
    }

    /** `express` (target) or `standard`: Stripe refuses Express for platforms registered in India, so those run on Standard until the platform account moves. */
    private function accountType(): string
    {
        return config('services.stripe.account_type', 'express');
    }
}
