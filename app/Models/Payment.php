<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A charge attempt that reached Stripe (one per payment intent). Never hard-deleted. */
#[Fillable(['provider_id', 'subscription_id', 'invoice_id', 'stripe_invoice_id', 'status', 'amount', 'amount_refunded', 'currency', 'failure_code', 'failure_message', 'paid_at', 'stripe_payment_intent_id', 'stripe_charge_id', 'stripe_event_at'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'amount_refunded' => 'integer', 'paid_at' => 'datetime', 'stripe_event_at' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundable(): int
    {
        return max(0, $this->amount - $this->amount_refunded);
    }
}
