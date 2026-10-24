<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\ListPaymentsRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\BillingMetrics;
use App\Services\Dunning\DunningMetrics;
use App\Services\Dunning\DunningService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Invoices, payments and the overview numbers for the signed-in provider. */
class BillingController extends Controller
{
    public function overview(BillingMetrics $metrics, DunningMetrics $dunning, TenantContext $tenancy): JsonResponse
    {
        return ApiResponse::success([...$metrics->overview($tenancy->current()), 'dunning' => $dunning->forProvider()]);
    }

    public function invoices(ListInvoicesRequest $request): JsonResponse
    {
        $invoices = Invoice::query()->with('providerCustomer.user')
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('dunning'), fn ($query) => $query->whereIn('dunning_status', DunningService::ACTIVE))
            ->orderBy($request->sortColumn(ListInvoicesRequest::SORTABLE, 'created_at'), $request->validated('sort') ? $request->sortDirection() : 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($invoices, InvoiceResource::class);
    }

    public function payments(ListPaymentsRequest $request): JsonResponse
    {
        $payments = Payment::query()->with('refunds')
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy($request->sortColumn(ListPaymentsRequest::SORTABLE, 'created_at'), $request->validated('sort') ? $request->sortDirection() : 'desc')
            ->paginate($request->perPage());

        return ApiResponse::paginated($payments, PaymentResource::class);
    }
}
