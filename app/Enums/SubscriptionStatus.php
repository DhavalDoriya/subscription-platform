<?php

namespace App\Enums;

/** FR-C2. Stripe knows most of these; `grace` and `expired` are ours (dunning engine, Phase 6). */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** Statuses in which the customer still gets the provider's product (FR-C8). */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue, self::Grace], true);
    }

    /** Still a live subscription that can be changed or cancelled. */
    public function isLive(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue, self::Grace, self::Paused], true);
    }

    /** Maps a Stripe subscription status. `grace` is never reported by Stripe, so a local grace survives `past_due`. */
    public static function fromStripe(string $status, ?self $current = null): self
    {
        return match ($status) {
            'trialing' => self::Trialing,
            'active' => self::Active,
            'past_due', 'unpaid' => $current === self::Grace ? self::Grace : self::PastDue,
            'paused' => self::Paused,
            'canceled' => self::Canceled,
            'incomplete_expired' => self::Expired,
            default => self::Incomplete,
        };
    }
}
