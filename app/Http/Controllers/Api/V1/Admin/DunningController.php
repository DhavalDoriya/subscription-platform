<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DunningEventResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentAttemptResource;
use App\Models\Invoice;
use App\Services\Dunning\DunningActions;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/** The same failed-payment actions for any provider's invoice (FR-AD6, FR-R9). */
class DunningController extends Controller
{
    public function __construct(private DunningActions $actions) {}

    public function show(string $invoice): JsonResponse
    {
        $model = Invoice::with(['attempts', 'dunningEvents'])->findOrFail($invoice);

        return ApiResponse::success([
            'attempts' => PaymentAttemptResource::collection($model->attempts)->resolve(),
            'events' => DunningEventResource::collection($model->dunningEvents)->resolve(),
        ]);
    }

    public function retry(string $invoice): JsonResponse
    {
        return ApiResponse::success(new InvoiceResource($this->actions->retry(Invoice::with(['provider', 'providerCustomer.user'])->findOrFail($invoice))));
    }

    public function resolve(string $invoice): JsonResponse
    {
        return ApiResponse::success(new InvoiceResource($this->actions->resolve(Invoice::with(['provider', 'providerCustomer.user'])->findOrFail($invoice))));
    }
}
