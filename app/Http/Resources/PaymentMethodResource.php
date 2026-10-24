<?php

namespace App\Http\Resources;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Display details of a saved card only; the Stripe id and anything card-number-like never leave the server. */
/** @mixin PaymentMethod */
class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'is_default' => $this->is_default,
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name, 'slug' => $this->provider->slug]),
        ];
    }
}
