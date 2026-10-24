<?php

namespace App\Http\Resources;

use App\Models\Price;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Price */
class PriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'interval_count' => $this->interval_count,
            'status' => $this->status,
            'synced' => $this->stripe_price_id !== null,
        ];
    }
}
