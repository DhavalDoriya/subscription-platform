<?php

namespace App\Models;

use App\Enums\DeclineType;
use App\Models\Concerns\TenantOwned;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One try at collecting an invoice (FR-R4): Stripe's first attempt, our scheduled retries and manual retries. */
#[Fillable(['provider_id', 'invoice_id', 'subscription_id', 'attempt_number', 'kind', 'status', 'decline_code', 'decline_type', 'failure_message', 'stripe_payment_intent_id', 'attempted_at', 'next_retry_at'])]
class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'decline_type' => DeclineType::class,
            'attempted_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
