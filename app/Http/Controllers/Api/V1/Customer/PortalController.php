<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\ListRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Dunning\DunningService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What a customer sees in their account: subscriptions and billing history across all providers (FR-CP1, FR-CP2). */
class PortalController extends Controller
{
    public function subscriptions(ListRequest $request): JsonResponse
    {
        $subscriptions = Subscription::ownedBy($request->user('api'))->with(['plan', 'provider'])
            ->whereNotIn('status', ['incomplete', 'expired']) // abandoned checkouts are not "mine"
            ->latest()->paginate($request->perPage());

        return ApiResponse::paginated($subscriptions, SubscriptionResource::class);
    }

    public function invoices(ListInvoicesRequest $request): JsonResponse
    {
        $user = $request->user('api');

        $invoices = Invoice::with('provider')
            ->whereHas('providerCustomer', fn ($link) => $link->where('user_id', $user->id))
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()->paginate($request->perPage());

        return ApiResponse::paginated($invoices, InvoiceResource::class);
    }

    /** Invoices of mine whose payment failed and is still being worked on, with what I can do about each (FR-R3, FR-R8). */
    public function paymentIssues(Request $request): JsonResponse
    {
        $invoices = Invoice::with(['provider', 'subscription.plan', 'attempts'])
            ->whereHas('providerCustomer', fn ($link) => $link->where('user_id', $request->user('api')->id))
            ->where('status', 'open')->whereIn('dunning_status', DunningService::ACTIVE)
            ->oldest('first_failed_at')->get();

        return ApiResponse::success($invoices->map(fn (Invoice $invoice) => [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription_id,
            'subscription_status' => $invoice->subscription?->status->value,
            'amount_due' => $invoice->amount_due,
            'currency' => $invoice->currency,
            'dunning_status' => $invoice->dunning_status,
            'decline_type' => $invoice->attempts->last()?->decline_type?->value,
            'failure_message' => $invoice->attempts->last()?->failure_message,
            'next_retry_at' => $invoice->next_retry_at?->toIso8601String(),
            'grace_ends_at' => $invoice->subscription?->grace_ends_at?->toIso8601String(),
            'hosted_invoice_url' => $invoice->hosted_invoice_url,
            'plan' => ['name' => $invoice->subscription?->plan?->name],
            'provider' => ['id' => $invoice->provider->id, 'name' => $invoice->provider->name, 'slug' => $invoice->provider->slug],
        ])->values());
    }
}
