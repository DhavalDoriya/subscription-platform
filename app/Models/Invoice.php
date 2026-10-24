<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One invoice per billing cycle, mirrored from Stripe. Amounts are integer minor units. */
#[Fillable(['provider_id', 'subscription_id', 'provider_customer_id', 'number', 'status', 'currency', 'amount_due', 'amount_paid', 'hosted_invoice_url', 'invoice_pdf_url', 'period_start', 'period_end', 'due_at', 'paid_at', 'dunning_status', 'first_failed_at', 'next_retry_at', 'stripe_invoice_id', 'stripe_event_at'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return [
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'first_failed_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'stripe_event_at' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function providerCustomer(): BelongsTo
    {
        return $this->belongsTo(ProviderCustomer::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_number');
    }

    public function dunningEvents(): HasMany
    {
        return $this->hasMany(DunningEvent::class)->orderBy('occurred_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
