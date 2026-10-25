<?php

namespace App\Services\Commission;

use App\Models\Commission;
use App\Models\Payment;
use App\Models\Subscription;

/**
 * Keeps one commission row per successful payment (FR-CM3). The rate is the one the subscription was sold with, i.e.
 * the application_fee_percent Stripe applied, so our numbers match Stripe's. Later rate changes never touch old rows.
 */
class CommissionRecorder
{
    /** Called when a payment succeeds; safe to call again for the same payment. */
    public function record(Payment $payment): ?Commission
    {
        if ($payment->status === 'failed' || Commission::where('payment_id', $payment->id)->exists()) {
            return null;
        }

        $subscription = $payment->subscription_id ? Subscription::find($payment->subscription_id) : null;
        $rate = (string) ($subscription?->application_fee_percent ?? '0.00');

        if ((float) $rate <= 0) {
            return null;
        }

        $commission = Commission::create([
            'provider_id' => $payment->provider_id,
            'payment_id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
            'currency' => $payment->currency,
            'gross_amount' => $payment->amount,
            'rate_percent' => $rate,
            'commission_amount' => (int) round($payment->amount * (float) $rate / 100, 0, PHP_ROUND_HALF_UP),
        ]);

        return $this->adjust($payment) ?? $commission;
    }

    /** Gives back the part of the fee that matches the refunded part of the payment (a refund returns the fee pro rata). */
    public function adjust(Payment $payment): ?Commission
    {
        $commission = Commission::where('payment_id', $payment->id)->first();

        if (! $commission) {
            return null;
        }

        $refunded = min((int) $payment->amount_refunded, $commission->gross_amount);
        $commission->forceFill([
            'refunded_amount' => $refunded,
            'commission_refunded' => $commission->gross_amount > 0 ? (int) round($commission->commission_amount * $refunded / $commission->gross_amount, 0, PHP_ROUND_HALF_UP) : 0,
        ])->save();

        return $commission;
    }

    /**
     * A lost dispute takes the money back from the provider, so the platform gives its fee back too (FR-CM3). Returns
     * whether there was a fee to give back, so the caller knows to ask Stripe for the application fee.
     */
    public function reverseForLostDispute(Payment $payment): bool
    {
        $commission = Commission::where('payment_id', $payment->id)->first();

        if (! $commission || $commission->dispute_lost_at) {
            return false;
        }

        $commission->forceFill(['commission_refunded' => $commission->commission_amount, 'dispute_lost_at' => now()])->save();

        return true;
    }

    public function hasCommission(Payment $payment): bool
    {
        return Commission::where('payment_id', $payment->id)->exists();
    }
}
