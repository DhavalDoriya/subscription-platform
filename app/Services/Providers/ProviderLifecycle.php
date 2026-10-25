<?php

namespace App\Services\Providers;

use App\Enums\ProviderStatus;
use App\Exceptions\ApiException;
use App\Models\Provider;
use App\Models\User;
use App\Services\Billing\ProviderBillingPause;
use App\Services\Mail\TemplateMail;
use Throwable;

/** The one place a provider's status changes: enforces the lifecycle and writes the audit entry. */
class ProviderLifecycle
{
    public function __construct(private ProviderBillingPause $billing, private TemplateMail $mail) {}

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

        $this->notifyOwner($provider, $from, $to, $reason);

        return $provider;
    }

    /** FR-E1: tell the owner about approval, rejection, suspension and reactivation. A mail failure never blocks the change. */
    private function notifyOwner(Provider $provider, ProviderStatus $from, ProviderStatus $to, ?string $reason): void
    {
        $template = match (true) {
            $to === ProviderStatus::Approved => 'provider.approved',
            $to === ProviderStatus::Rejected => 'provider.rejected',
            $to === ProviderStatus::Suspended => 'provider.suspended',
            $to === ProviderStatus::Active && $from === ProviderStatus::Suspended => 'provider.reactivated',
            default => null,
        };
        $owner = $template ? $provider->owner() : null;

        if (! $owner) {
            return;
        }

        try {
            $this->mail->send($template, $owner->email, [
                'user.name' => $owner->name,
                'provider.name' => $provider->name,
                'reason' => $reason,
                'dashboard_url' => rtrim((string) config('app.frontend_url'), '/').'/dashboard',
            ], $provider->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private const EVENTS = [
        'approved' => 'approved', 'rejected' => 'rejected', 'active' => 'activated',
        'inactive' => 'deactivated', 'suspended' => 'suspended', 'pending' => 'reopened',
    ];
}
