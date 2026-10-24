<?php

namespace App\Services\Stripe;

/** Turns a raw Stripe account (SDK object, array or webhook payload) into the few facts we store, and our onboarding status. */
final class StripeAccountState
{
    /**
     * @return array{id: string, details_submitted: bool, charges_enabled: bool, payouts_enabled: bool, disabled_reason: ?string, currently_due: array<int, string>}
     */
    public static function normalize(mixed $account): array
    {
        $account = json_decode((string) json_encode($account), true) ?? [];

        return [
            'id' => (string) ($account['id'] ?? ''),
            'details_submitted' => (bool) ($account['details_submitted'] ?? false),
            'charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
            'disabled_reason' => $account['requirements']['disabled_reason'] ?? null,
            'currently_due' => array_values($account['requirements']['currently_due'] ?? []),
        ];
    }

    /** not_started is "no account yet" and is set by the caller; here: pending, restricted or enabled. */
    public static function status(array $state): string
    {
        return match (true) {
            ! $state['details_submitted'] => 'pending',
            $state['charges_enabled'] && $state['payouts_enabled'] => 'enabled',
            default => 'restricted',
        };
    }
}
