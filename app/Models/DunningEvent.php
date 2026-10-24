<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\DunningEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The dunning timeline of an invoice (what we told the customer and what we decided), FR-R6. */
#[Fillable(['provider_id', 'invoice_id', 'subscription_id', 'type', 'payload', 'occurred_at'])]
class DunningEvent extends Model
{
    /** @use HasFactory<DunningEventFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
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
