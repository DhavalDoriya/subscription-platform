<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelSubscriptionRequest;
use App\Http\Requests\ListSubscriptionsRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Subscribers' subscriptions as the provider sees them. The `provider.tenant` scope limits every lookup to their own. */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function index(ListSubscriptionsRequest $request): JsonResponse
    {
        $subscriptions = Subscription::query()->with(['plan', 'providerCustomer.user'])
            ->filter($request->filters())
            ->whereNotIn('status', ['incomplete', 'expired'])
            ->orderBy($request->sortColumn(ListSubscriptionsRequest::SORTABLE, 'created_at'), $request->sortDirection() === 'asc' && ! $request->validated('sort') ? 'desc' : $request->sortDirection())
            ->paginate($request->perPage());

        return ApiResponse::paginated($subscriptions, SubscriptionResource::class);
    }

    public function show(string $subscription): JsonResponse
    {
        $model = Subscription::with(['plan', 'providerCustomer.user', 'invoices' => fn ($q) => $q->latest(), 'payments' => fn ($q) => $q->latest()->with('refunds')])->findOrFail($subscription);

        return ApiResponse::success([
            ...(new SubscriptionResource($model))->resolve(),
            'invoices' => InvoiceResource::collection($model->invoices)->resolve(),
            'payments' => PaymentResource::collection($model->payments)->resolve(),
        ]);
    }

    public function cancel(CancelSubscriptionRequest $request, string $subscription): JsonResponse
    {
        $model = Subscription::with(['plan', 'providerCustomer.user'])->findOrFail($subscription);

        return ApiResponse::success(new SubscriptionResource($this->subscriptions->cancel($model, $request->atPeriodEnd(), $request->user('api'))));
    }
}
