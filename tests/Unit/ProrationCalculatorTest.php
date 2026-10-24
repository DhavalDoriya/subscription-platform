<?php

use App\Services\Billing\ProrationCalculator;

const DAY = 86400;

it('FR-C3 prorates an upgrade half way through the period with integer money', function () {
    // 30-day period, 15 days left: unused 1000 => credit 500, new plan 3000 => charge 1500.
    $result = ProrationCalculator::preview(1000, 3000, periodStart: 0, periodEnd: 30 * DAY, now: 15 * DAY);

    expect($result)->toBe(['credit' => 500, 'charge' => 1500, 'net' => 1000, 'remaining_ratio' => 0.5]);
});

it('FR-C3 a downgrade leaves a credit (negative net) for the next invoice', function () {
    $result = ProrationCalculator::preview(3000, 1000, 0, 30 * DAY, 15 * DAY);

    expect($result['credit'])->toBe(1500)->and($result['charge'])->toBe(500)->and($result['net'])->toBe(-1000);
});

it('FR-C3 rounds half up to whole minor units and never leaves fractions', function () {
    // 999 * 10/30 = 333.0 ; 1000 * 10/30 = 333.33 -> 333 ; 1001 * 10/30 = 333.67 -> 334
    expect(ProrationCalculator::preview(999, 1001, 0, 30 * DAY, 20 * DAY))->toMatchArray(['credit' => 333, 'charge' => 334]);
    expect(ProrationCalculator::preview(1000, 1000, 0, 30 * DAY, 20 * DAY))->toMatchArray(['credit' => 333, 'charge' => 333, 'net' => 0]);
});

it('FR-C3 changing at the very start credits and charges the full period, at the end nothing', function () {
    expect(ProrationCalculator::preview(1000, 2500, 0, 30 * DAY, 0))->toMatchArray(['credit' => 1000, 'charge' => 2500, 'net' => 1500]);
    expect(ProrationCalculator::preview(1000, 2500, 0, 30 * DAY, 30 * DAY))->toMatchArray(['credit' => 0, 'charge' => 0, 'net' => 0]);
    expect(ProrationCalculator::preview(1000, 2500, 0, 30 * DAY, 40 * DAY))->toMatchArray(['credit' => 0, 'charge' => 0, 'net' => 0]); // already past the period
});

it('FR-C3 survives large amounts without overflowing', function () {
    $result = ProrationCalculator::preview(1_000_000_00, 1_000_000_00, 0, 365 * DAY, 100 * DAY);

    expect($result['credit'])->toBe($result['charge'])->and($result['net'])->toBe(0)->and($result['credit'])->toBeInt();
});
