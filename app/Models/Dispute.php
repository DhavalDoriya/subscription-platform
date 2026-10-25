<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\DisputeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A chargeback opened by a customer's bank against a payment. Stripe is the source of truth; we mirror it. */
#[Fillable(['provider_id', 'payment_id', 'stripe_dispute_id', 'amount', 'currency', 'reason', 'status', 'evidence_due_by', 'stripe_event_at'])]
class Dispute extends Model
{
    /** @use HasFactory<DisputeFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'evidence_due_by' => 'datetime', 'stripe_event_at' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
