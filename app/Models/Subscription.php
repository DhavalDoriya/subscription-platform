<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\TenantOwned;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A customer's subscription to one plan of one provider; a mirror of the Stripe subscription (PRD FR-PM6). */
#[Fillable(['provider_id', 'provider_customer_id', 'plan_id', 'price_id', 'status', 'amount', 'currency', 'interval', 'interval_count', 'cancel_at_period_end', 'current_period_start', 'current_period_end', 'trial_ends_at', 'canceled_at', 'ended_at', 'paused_at', 'pause_reason', 'grace_ends_at', 'stripe_subscription_id', 'stripe_event_at', 'application_fee_percent'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'amount' => 'integer',
            'application_fee_percent' => 'decimal:2',
            'interval_count' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'trial_reminder_for' => 'datetime',
            'renewal_reminder_for' => 'datetime',
            'paused_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'stripe_event_at' => 'integer',
        ];
    }

    public function providerCustomer(): BelongsTo
    {
        return $this->belongsTo(ProviderCustomer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** The subscriptions a customer holds across all providers. */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->whereHas('providerCustomer', fn ($link) => $link->where('user_id', $user->id));
    }

    /**
     * Shared filters of the provider and admin listings: status, plan, provider and a search on the customer's name or email.
     *
     * @param  array{status?: ?string, plan_id?: ?string, provider_id?: ?string, search?: ?string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['plan_id'] ?? null, fn ($q, $plan) => $q->where('plan_id', $plan))
            ->when($filters['provider_id'] ?? null, fn ($q, $provider) => $q->where('provider_id', $provider))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas('providerCustomer', fn ($link) => $link->matchingUser($search)));
    }

    public function hasAccess(): bool
    {
        return $this->status->grantsAccess();
    }
}
