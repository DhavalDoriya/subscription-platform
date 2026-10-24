<?php

use App\Enums\DeclineType;
use App\Services\Dunning\DeclineClassifier;

// FR-R3: soft declines are retried, hard declines never are, authentication-required asks the customer to confirm.
dataset('declines', [
    'insufficient funds' => ['insufficient_funds', DeclineType::Soft],
    'processing error' => ['processing_error', DeclineType::Soft],
    'generic decline' => ['generic_decline', DeclineType::Soft],
    'issuer not available' => ['issuer_not_available', DeclineType::Soft],
    'try again later' => ['try_again_later', DeclineType::Soft],
    'velocity exceeded' => ['card_velocity_exceeded', DeclineType::Soft],
    'withdrawal limit' => ['withdrawal_count_limit_exceeded', DeclineType::Soft],
    'reenter transaction' => ['reenter_transaction', DeclineType::Soft],
    'no code at all' => [null, DeclineType::Soft],
    'a code we have never seen' => ['brand_new_code', DeclineType::Soft],

    'stolen card' => ['stolen_card', DeclineType::Hard],
    'lost card' => ['lost_card', DeclineType::Hard],
    'pickup card' => ['pickup_card', DeclineType::Hard],
    'fraudulent' => ['fraudulent', DeclineType::Hard],
    'do not honor' => ['do_not_honor', DeclineType::Hard],
    'expired card' => ['expired_card', DeclineType::Hard],
    'invalid account' => ['invalid_account', DeclineType::Hard],
    'incorrect number' => ['incorrect_number', DeclineType::Hard],
    'invalid number' => ['invalid_number', DeclineType::Hard],
    'card not supported' => ['card_not_supported', DeclineType::Hard],
    'restricted card' => ['restricted_card', DeclineType::Hard],
    'security violation' => ['security_violation', DeclineType::Hard],
    'new account information available' => ['new_account_information_available', DeclineType::Hard],
    'stop payment order' => ['stop_payment_order', DeclineType::Hard],
    'revocation of authorization' => ['revocation_of_authorization', DeclineType::Hard],

    'authentication required' => ['authentication_required', DeclineType::AuthRequired],
    'authentication not handled' => ['authentication_not_handled', DeclineType::AuthRequired],
    'requires action' => ['requires_action', DeclineType::AuthRequired],
]);

it('FR-R3 classifies decline codes', function (?string $code, DeclineType $expected) {
    expect((new DeclineClassifier)->classify($code))->toBe($expected);
})->with('declines');

it('FR-R3 only soft declines are retried', function () {
    expect(DeclineType::Soft->isRetryable())->toBeTrue()
        ->and(DeclineType::Hard->isRetryable())->toBeFalse()
        ->and(DeclineType::AuthRequired->isRetryable())->toBeFalse();
});

it('FR-R3 hard and authentication declines need the customer to act', function () {
    expect(DeclineType::Soft->needsCustomerAction())->toBeFalse()
        ->and(DeclineType::Hard->needsCustomerAction())->toBeTrue()
        ->and(DeclineType::AuthRequired->needsCustomerAction())->toBeTrue();
});
