<?php

namespace App\Services\Providers;

use App\Enums\ProviderStatus;
use App\Exceptions\ApiException;
use App\Models\Provider;
use App\Models\User;
use App\Services\Billing\ProviderBillingPause;

/** The one place a provider's status changes: enforces the lifecycle and writes the audit entry. */
class ProviderLifecycle
{
    public function __construct(private ProviderBillingPause $billing) {}

    public function transition(Provider $provider, ProviderStatus $to, ?User $actor, ?string $reason = null): Provider
    {
        $from = $provider->status;

        if (! $from->canTransitionTo($to)) {
            throw new ApiException(__('api.providers.invalid_transition', ['from' => $from->value, 'to' => $to->value]), 409, 'invalid_transition');
        }

        $provider->status = $to;

        if ($to === ProviderStatus::Approved) {
            $provider->approved_at = now();
            $provider->rejection_reason = null;
        }

        if ($to === ProviderStatus::Rejected) {
            $provider->rejection_reason = $reason;
        }

        $provider->save();

        // FR-P9: deactivation pauses billing, reactivation resumes it.
        if ($to->pausesBilling() && ! $from->pausesBilling()) {
            $this->billing->pause($provider);
        } elseif ($from->pausesBilling() && ! $to->pausesBilling()) {
            $this->billing->resume($provider);
        }

        $log = activity()->performedOn($provider)->withProperties(array_filter(['from' => $from->value, 'to' => $to->value, 'reason' => $reason]));
        if ($actor) {
            $log->causedBy($actor); // null = the system (e.g. a Stripe webhook)
        }
        $log->log('provider.'.self::EVENTS[$to->value]);

        return $provider;
    }

    private const EVENTS = [
        'approved' => 'approved', 'rejected' => 'rejected', 'active' => 'activated',
        'inactive' => 'deactivated', 'suspended' => 'suspended', 'pending' => 'reopened',
    ];
}
