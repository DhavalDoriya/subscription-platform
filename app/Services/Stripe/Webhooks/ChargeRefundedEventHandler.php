<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\Payment;
use App\Models\Provider;
use App\Models\Refund;

/** charge.refunded: sets the refunded total from Stripe (never adds to it), so replays and our own refunds agree. */
class ChargeRefundedEventHandler extends ConnectEventHandler
{
    protected function process(Provider $provider, array $object, int $at, string $type): void
    {
        $payment = Payment::where('stripe_charge_id', $object['id'] ?? '')->first()
            ?? Payment::where('stripe_payment_intent_id', $this->id($object['payment_intent'] ?? null) ?? '')->first();

        if (! $payment || ($payment->stripe_event_at !== null && $at < $payment->stripe_event_at)) {
            return;
        }

        $refunded = (int) ($object['amount_refunded'] ?? 0);

        $payment->forceFill([
            'amount_refunded' => $refunded,
            'status' => match (true) {
                $refunded >= $payment->amount => 'refunded',
                $refunded > 0 => 'partially_refunded',
                default => $payment->status,
            },
            'stripe_event_at' => $at,
        ])->save();

        foreach ($object['refunds']['data'] ?? [] as $refund) {
            Refund::firstOrCreate(['stripe_refund_id' => $refund['id']], [
                'provider_id' => $provider->id,
                'payment_id' => $payment->id,
                'amount' => $refund['amount'],
                'currency' => $refund['currency'],
                'reason' => $refund['reason'] ?? null,
                'status' => $refund['status'] ?? 'succeeded',
            ]);
        }
    }
}
