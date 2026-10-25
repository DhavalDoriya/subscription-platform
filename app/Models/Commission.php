<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\CommissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The platform fee of one payment with the rate it was sold at (FR-CM3). Never hard-deleted. */
#[Fillable(['provider_id', 'payment_id', 'subscription_id', 'currency', 'gross_amount', 'refunded_amount', 'rate_percent', 'commission_amount', 'commission_refunded', 'dispute_lost_at'])]
class Commission extends Model
{
    /** @use HasFactory<CommissionFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer', 'refunded_amount' => 'integer', 'commission_amount' => 'integer', 'commission_refunded' => 'integer',
            'rate_percent' => 'decimal:2', 'dispute_lost_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The fee the platform keeps after refunds. */
    public function netCommission(): int
    {
        return $this->commission_amount - $this->commission_refunded;
    }

    /** What the provider receives: the gross, minus refunds, minus the fee the platform keeps. */
    public function providerNet(): int
    {
        return $this->dispute_lost_at ? 0 : $this->gross_amount - $this->refunded_amount - $this->netCommission();
    }
}
