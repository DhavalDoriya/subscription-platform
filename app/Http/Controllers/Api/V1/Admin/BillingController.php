<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelSubscriptionRequest;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\ListSubscriptionsRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use App\Services\Dunning\DunningService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Subscriptions and invoices across every provider (FR-AD6). */
class BillingController extends Controller
{
    public function subscriptions(ListSubscriptionsRequest $request): JsonResponse
    {
        $subscriptions = Subscription::query()->with(['plan', 'provider', 'providerCustomer.user'])
            ->filter($request->filters())
            ->orderBy($request->sortColumn(ListSubscriptionsRequest::SORTABLE, 'created_at'), $request->validated('sort') ? $request->sortDirection() : 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($subscriptions, SubscriptionResource::class);
    }

    public function invoices(ListInvoicesRequest $request): JsonResponse
    {
        $invoices = Invoice::query()->with(['provider', 'providerCustomer.user'])
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('dunning'), fn ($query) => $query->whereIn('dunning_status', DunningService::ACTIVE))
            ->when($request->search(), fn ($query, string $search) => $query->where(fn ($q) => $q->whereRaw('lower(number) like ?', ['%'.mb_strtolower($search).'%'])->orWhereHas('providerCustomer', fn ($link) => $link->matchingUser($search))))
            ->orderBy($request->sortColumn(ListInvoicesRequest::SORTABLE, 'created_at'), $request->validated('sort') ? $request->sortDirection() : 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($invoices, InvoiceResource::class);
    }

    public function cancel(CancelSubscriptionRequest $request, string $subscription, SubscriptionService $subscriptions): JsonResponse
    {
        $model = Subscription::with(['plan', 'provider', 'providerCustomer.user'])->findOrFail($subscription);

        return ApiResponse::success(new SubscriptionResource($subscriptions->cancel($model, $request->atPeriodEnd(), $request->user('api'))));
    }
}
