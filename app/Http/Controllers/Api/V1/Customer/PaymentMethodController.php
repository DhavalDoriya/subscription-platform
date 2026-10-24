<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Services\Dunning\DunningService;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeGateway;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A customer's saved cards, per provider (cards live on each provider's connected account). */
class PaymentMethodController extends Controller
{
    public function __construct(private StripeGateway $stripe, private SettingsService $settings, private DunningService $dunning) {}

    public function index(Request $request): JsonResponse
    {
        $methods = PaymentMethod::with('provider')
            ->whereHas('providerCustomer', fn ($link) => $link->where('user_id', $request->user('api')->id))
            ->orderByDesc('is_default')->orderBy('created_at')->get();

        return ApiResponse::success(PaymentMethodResource::collection($methods)->resolve());
    }

    /** A SetupIntent so the browser can collect a new card with the Payment Element. */
    public function setupIntent(Request $request): JsonResponse
    {
        $link = ProviderCustomer::with('provider')
            ->where('user_id', $request->user('api')->id)->where('provider_id', $request->validate(['provider_id' => ['required', 'uuid']])['provider_id'])
            ->whereNotNull('stripe_customer_id')->firstOrFail();

        $secret = $this->stripe->createSetupIntent($link->provider->stripe_account_id, $link->stripe_customer_id, "customer-{$link->id}-setup-".now()->timestamp);

        return ApiResponse::success([
            'client_secret' => $secret,
            'stripe_account' => $link->provider->stripe_account_id,
            'publishable_key' => $this->settings->get('stripe.publishable_key'),
        ]);
    }

    public function makeDefault(Request $request, string $method): JsonResponse
    {
        $model = $this->owned($request, $method);

        $this->stripe->setDefaultPaymentMethod($model->provider->stripe_account_id, $model->providerCustomer->stripe_customer_id, $model->stripe_payment_method_id, "customer-{$model->provider_customer_id}-default-{$model->id}-".now()->timestamp);

        PaymentMethod::where('provider_customer_id', $model->provider_customer_id)->update(['is_default' => false]);
        $model->update(['is_default' => true]);

        $this->dunning->retryOpenInvoices($model->providerCustomer); // FR-R8

        return ApiResponse::success(new PaymentMethodResource($model));
    }

    public function destroy(Request $request, string $method): JsonResponse
    {
        $model = $this->owned($request, $method);

        $isLast = PaymentMethod::where('provider_customer_id', $model->provider_customer_id)->whereKeyNot($model->id)->doesntExist();
        $hasLiveSubscription = Subscription::where('provider_customer_id', $model->provider_customer_id)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'grace'])->exists();

        if ($isLast && $hasLiveSubscription) {
            throw new ApiException(__('api.billing.payment_method_in_use'), 409, 'payment_method_in_use');
        }

        $this->stripe->detachPaymentMethod($model->provider->stripe_account_id, $model->stripe_payment_method_id, "payment-method-{$model->id}-detach");
        $model->delete();

        return ApiResponse::noContent();
    }

    private function owned(Request $request, string $id): PaymentMethod
    {
        return PaymentMethod::with(['provider', 'providerCustomer'])
            ->whereHas('providerCustomer', fn ($link) => $link->where('user_id', $request->user('api')->id))
            ->findOrFail($id);
    }
}
