<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Amount is an integer in minor units (1900 = 19.00). Once on Stripe a price never changes; we replace it. */
#[Fillable(['provider_id', 'plan_id', 'amount', 'currency', 'interval', 'interval_count', 'status', 'stripe_price_id'])]
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'interval_count' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
