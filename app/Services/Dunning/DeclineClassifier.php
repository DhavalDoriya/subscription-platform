<?php

namespace App\Services\Dunning;

use App\Enums\DeclineType;

/**
 * Sorts a Stripe decline code into soft, hard or authentication-required (FR-R3). Anything unknown or missing is soft:
 * a few wasted retries cost less than giving up on a customer who would have paid.
 */
class DeclineClassifier
{
    private const HARD = [
        'stolen_card', 'lost_card', 'pickup_card', 'fraudulent', 'do_not_honor', 'expired_card', 'invalid_account',
        'incorrect_number', 'invalid_number', 'card_not_supported', 'restricted_card', 'security_violation',
        'new_account_information_available', 'stop_payment_order', 'revocation_of_authorization', 'invalid_expiry_month',
        'invalid_expiry_year', 'incorrect_cvc', 'invalid_cvc',
    ];

    private const AUTH = ['authentication_required', 'authentication_not_handled', 'requires_action'];

    public function classify(?string $code): DeclineType
    {
        return match (true) {
            in_array($code, self::AUTH, true) => DeclineType::AuthRequired,
            in_array($code, self::HARD, true) => DeclineType::Hard,
            default => DeclineType::Soft,
        };
    }
}
