<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Full or partial refund of a payment, by the provider or an admin (FR-C10). */
#[Fillable(['provider_id', 'payment_id', 'created_by', 'amount', 'currency', 'reason', 'status', 'stripe_refund_id'])]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
