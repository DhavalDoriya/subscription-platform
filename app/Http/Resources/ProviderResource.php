<?php

namespace App\Http\Resources;

use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Provider */
class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'business_category' => $this->business_category,
            'country' => $this->country,
            'support_email' => $this->support_email,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'branding' => ['primary_color' => $this->settings['branding']['primary_color'] ?? null],
            'legal' => [
                'terms_url' => $this->settings['legal']['terms_url'] ?? null,
                'privacy_url' => $this->settings['legal']['privacy_url'] ?? null,
                'refund_url' => $this->settings['legal']['refund_url'] ?? null,
            ],
            'tax_enabled' => (bool) ($this->settings['tax']['enabled'] ?? false),
            'stripe_status' => $this->stripe_status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
