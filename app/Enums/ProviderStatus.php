<?php

namespace App\Enums;

/**
 * Provider lifecycle (PRD FR-P2): pending -> approved -> active <-> inactive / suspended, plus rejected.
 * "approved" means vetted but not yet selling (e.g. Stripe onboarding open); "active" is fully live.
 */
enum ProviderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Active, self::Inactive, self::Suspended],
            self::Active => [self::Inactive, self::Suspended],
            self::Inactive, self::Suspended => [self::Active],
            self::Rejected => [self::Approved],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Storefront visible and checkout allowed. */
    public function isOperational(): bool
    {
        return in_array($this, [self::Approved, self::Active], true);
    }

    /** FR-P9: existing subscriptions have collection paused while the provider is in this state. */
    public function pausesBilling(): bool
    {
        return in_array($this, [self::Inactive, self::Suspended], true);
    }
}
