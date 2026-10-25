<?php

namespace App\Http\Resources;

use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Commission */
class CommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'currency' => $this->currency,
            'gross_amount' => $this->gross_amount,
            'refunded_amount' => $this->refunded_amount,
            'rate_percent' => (string) $this->rate_percent,
            'commission_amount' => $this->commission_amount,
            'commission_refunded' => $this->commission_refunded,
            'net_commission' => $this->netCommission(),
            'provider_net' => $this->providerNet(),
            'created_at' => $this->created_at?->toIso8601String(),
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name, 'slug' => $this->provider->slug]),
        ];
    }
}
