<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Resources\DunningEventResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentAttemptResource;
use App\Models\Invoice;
use App\Services\Dunning\DunningActions;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Failed-payment handling for the signed-in provider's invoices (FR-R4, FR-R9). Another provider's invoice is a 404. */
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
        return ApiResponse::success(new InvoiceResource($this->actions->retry(Invoice::findOrFail($invoice))));
    }

    public function resolve(string $invoice): JsonResponse
    {
        return ApiResponse::success(new InvoiceResource($this->actions->resolve(Invoice::findOrFail($invoice))));
    }
}
