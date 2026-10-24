<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelSubscriptionRequest;
use App\Http\Requests\Customer\ChangePlanRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A customer's own subscriptions across all providers. Another customer's id is a plain 404. */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function cancel(CancelSubscriptionRequest $request, string $subscription): JsonResponse
    {
        $model = $this->owned($request, $subscription);

        return $this->respond($this->subscriptions->cancel($model, $request->atPeriodEnd(), $request->user('api')));
    }

    public function resume(Request $request, string $subscription): JsonResponse
    {
        return $this->respond($this->subscriptions->resume($this->owned($request, $subscription), $request->user('api')));
    }

    public function previewChange(ChangePlanRequest $request, string $subscription): JsonResponse
    {
        $model = $this->owned($request, $subscription);

        return ApiResponse::success($this->subscriptions->previewChange($model, $this->targetPrice($model, $request), $request->timing()));
    }

    public function change(ChangePlanRequest $request, string $subscription): JsonResponse
    {
        $model = $this->owned($request, $subscription);

        return $this->respond($this->subscriptions->changePlan($model, $this->targetPrice($model, $request), $request->timing(), $request->user('api')));
    }

    /** The prices this subscriber could switch to: live plans of the same provider in the same currency. */
    public function options(Request $request, string $subscription): JsonResponse
    {
        $model = $this->owned($request, $subscription);

        $prices = Price::withoutGlobalScopes()->with(['plan' => fn ($plan) => $plan->withoutGlobalScopes()])
            ->where('provider_id', $model->provider_id)->where('status', 'active')->where('currency', $model->currency)
            ->whereNotNull('stripe_price_id')->whereKeyNot($model->price_id)
            ->whereHas('plan', fn ($plan) => $plan->withoutGlobalScopes()->where('status', 'active'))
            ->orderBy('amount')->get();

        return ApiResponse::success($prices->map(fn (Price $price) => [
            'price_id' => $price->id,
            'plan_id' => $price->plan_id,
            'plan_name' => $price->plan->name,
            'amount' => $price->amount,
            'currency' => $price->currency,
            'interval' => $price->interval,
            'interval_count' => $price->interval_count,
        ])->values()->all());
    }

    private function owned(Request $request, string $id): Subscription
    {
        return Subscription::ownedBy($request->user('api'))->with(['plan', 'provider'])->findOrFail($id);
    }

    /** Only an active price of an active plan of the same provider; the tenant scope is off on this route, so say it explicitly. */
    private function targetPrice(Subscription $subscription, ChangePlanRequest $request): Price
    {
        return Price::withoutGlobalScopes()
            ->where('provider_id', $subscription->provider_id)->where('status', 'active')->whereNotNull('stripe_price_id')
            ->whereHas('plan', fn ($plan) => $plan->withoutGlobalScopes()->where('status', 'active'))
            ->findOrFail($request->validated('price_id'));
    }

    private function respond(Subscription $subscription): JsonResponse
    {
        return ApiResponse::success(new SubscriptionResource($subscription->load(['plan', 'provider'])));
    }
}
