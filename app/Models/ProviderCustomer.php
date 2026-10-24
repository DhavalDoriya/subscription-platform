<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\ProviderCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Case-insensitive search on the linked user's name or email. */
    public function scopeMatchingUser(Builder $query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';

        $query->whereHas('user', fn ($user) => $user->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like])));
    }
}
