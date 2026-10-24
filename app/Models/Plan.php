<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['provider_id', 'name', 'description', 'features', 'status', 'visibility', 'trial_days', 'sort_order', 'stripe_product_id'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['features' => 'array', 'trial_days' => 'integer', 'sort_order' => 'integer'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function activePrices(): HasMany
    {
        return $this->prices()->where('status', 'active');
    }
}
