<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'invoice_id' => $this->invoice_id,
            'status' => $this->status,
            'amount' => $this->amount,
            'amount_refunded' => $this->amount_refunded,
            'refundable' => $this->refundable(),
            'currency' => $this->currency,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'commission' => $this->whenLoaded('commission', fn () => $this->commission ? [
                'rate_percent' => (string) $this->commission->rate_percent,
                'amount' => $this->commission->commission_amount,
                'refunded' => $this->commission->commission_refunded,
                'net_fee' => $this->commission->netCommission(),
                'provider_net' => $this->commission->providerNet(),
            ] : null),
            'dispute' => $this->whenLoaded('dispute', fn () => $this->dispute ? ['status' => $this->dispute->status, 'amount' => $this->dispute->amount, 'reason' => $this->dispute->reason, 'evidence_due_by' => $this->dispute->evidence_due_by?->toIso8601String()] : null),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds'))->resolve($request),
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name, 'slug' => $this->provider->slug]),
        ];
    }
}
