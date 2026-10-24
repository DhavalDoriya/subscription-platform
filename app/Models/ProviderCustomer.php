<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\ProviderCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Link between a global customer and a provider; created only when they subscribe (PRD 3.5). */
#[Fillable(['provider_id', 'user_id', 'stripe_customer_id'])]
class ProviderCustomer extends Model
{
    /** @use HasFactory<ProviderCustomerFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
