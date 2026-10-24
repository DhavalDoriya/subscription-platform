<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'subscription_id' => $this->subscription_id,
            'currency' => $this->currency,
            'amount_due' => $this->amount_due,
            'amount_paid' => $this->amount_paid,
            'dunning_status' => $this->dunning_status,
            'first_failed_at' => $this->first_failed_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'hosted_invoice_url' => $this->hosted_invoice_url,
            'invoice_pdf_url' => $this->invoice_pdf_url,
            'period_start' => $this->period_start?->toIso8601String(),
            'period_end' => $this->period_end?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name, 'slug' => $this->provider->slug]),
            'customer' => $this->whenLoaded('providerCustomer', fn () => ['name' => $this->providerCustomer?->user?->name, 'email' => $this->providerCustomer?->user?->email]),
        ];
    }
}
