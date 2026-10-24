<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'has_access' => $this->hasAccess(),
            'plan' => $this->whenLoaded('plan', fn () => ['id' => $this->plan->id, 'name' => $this->plan->name]),
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name, 'slug' => $this->provider->slug]),
            'customer' => $this->whenLoaded('providerCustomer', fn () => ['name' => $this->providerCustomer->user?->name, 'email' => $this->providerCustomer->user?->email]),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'interval_count' => $this->interval_count,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
