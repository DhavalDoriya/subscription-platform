<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A saved card on the provider's connected account. Only display details are stored, never card numbers. */
#[Fillable(['provider_id', 'provider_customer_id', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'stripe_payment_method_id'])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['exp_month' => 'integer', 'exp_year' => 'integer', 'is_default' => 'boolean'];
    }

    public function providerCustomer(): BelongsTo
    {
        return $this->belongsTo(ProviderCustomer::class);
    }
}
