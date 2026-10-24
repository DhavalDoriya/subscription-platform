<?php

use App\Enums\ProviderStatus as S;

it('FR-P2 allows only the documented lifecycle transitions', function (S $from, S $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'pending -> approved' => [S::Pending, S::Approved, true],
    'pending -> rejected' => [S::Pending, S::Rejected, true],
    'pending -> active' => [S::Pending, S::Active, false],
    'approved -> active' => [S::Approved, S::Active, true],
    'approved -> suspended' => [S::Approved, S::Suspended, true],
    'active -> inactive' => [S::Active, S::Inactive, true],
    'active -> suspended' => [S::Active, S::Suspended, true],
    'inactive -> active' => [S::Inactive, S::Active, true],
    'suspended -> active' => [S::Suspended, S::Active, true],
    'active -> pending' => [S::Active, S::Pending, false],
    'active -> rejected' => [S::Active, S::Rejected, false],
    'rejected -> approved' => [S::Rejected, S::Approved, true],
    'rejected -> active' => [S::Rejected, S::Active, false],
    'active -> active' => [S::Active, S::Active, false],
]);

it('FR-S9 only approved and active providers are operational; others pause billing', function () {
    expect(S::Approved->isOperational())->toBeTrue()
        ->and(S::Active->isOperational())->toBeTrue()
        ->and(S::Pending->isOperational())->toBeFalse()
        ->and(S::Rejected->isOperational())->toBeFalse()
        ->and(S::Inactive->isOperational())->toBeFalse()
        ->and(S::Suspended->isOperational())->toBeFalse()
        ->and(S::Inactive->pausesBilling())->toBeTrue()
        ->and(S::Suspended->pausesBilling())->toBeTrue()
        ->and(S::Active->pausesBilling())->toBeFalse();
});
