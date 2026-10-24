<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\RefundPaymentRequest;
use App\Http\Resources\RefundResource;
use App\Models\Payment;
use App\Services\Billing\RefundService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(private RefundService $refunds) {}

    public function refund(RefundPaymentRequest $request, string $payment): JsonResponse
    {
        // Tenant-scoped lookup: another provider's payment is a 404.
        $model = Payment::findOrFail($payment);

        return ApiResponse::created(new RefundResource($this->refunds->refund($model, $request->validated('amount'), $request->validated('reason'), $request->user('api'))));
    }
}
