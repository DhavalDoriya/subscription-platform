<?php

namespace App\Services\Billing;

/**
 * What a plan change costs part-way through a billing period (FR-C3), in integer minor units. Stripe does the real
 * calculation; this is the same arithmetic so the customer sees the amount before confirming. Amounts are rounded
 * half up per line, never floats, so a preview can differ from Stripe's invoice by at most a minor unit.
 */
final class ProrationCalculator
{
    /**
     * @param  int  $periodStart  unix seconds
     * @return array{credit: int, charge: int, net: int, remaining_ratio: float} net > 0 is due now, net < 0 becomes credit on the next invoice
     */
    public static function preview(int $currentAmount, int $newAmount, int $periodStart, int $periodEnd, int $now): array
    {
        $total = max(1, $periodEnd - $periodStart);
        $remaining = min($total, max(0, $periodEnd - max($now, $periodStart)));

        $credit = self::share($currentAmount, $remaining, $total);
        $charge = self::share($newAmount, $remaining, $total);

        return ['credit' => $credit, 'charge' => $charge, 'net' => $charge - $credit, 'remaining_ratio' => round($remaining / $total, 6)];
    }

    /** amount * remaining / total, rounded half up, with integer maths only. */
    private static function share(int $amount, int $remaining, int $total): int
    {
        return intdiv(2 * $amount * $remaining + $total, 2 * $total);
    }
}
