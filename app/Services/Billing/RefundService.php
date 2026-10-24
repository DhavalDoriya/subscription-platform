<?php

namespace App\Services\Billing;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Facades\DB;

/** Full and partial refunds by a provider or an admin (FR-C10). Stripe is asked first; if it refuses, nothing is kept. */
class RefundService
{
    public function __construct(private StripeGateway $stripe) {}

    public function refund(Payment $payment, ?int $amount, ?string $reason, User $actor): Refund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $actor) {
            // Lock the row so two simultaneous refunds cannot both pass the "enough left" check.
            $payment = Payment::withoutGlobalScopes()->with('provider')->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($payment->status, ['succeeded', 'partially_refunded'], true) || ! $payment->stripe_payment_intent_id) {
                throw new ApiException(__('api.billing.not_refundable'), 409, 'not_refundable');
            }

            $amount ??= $payment->refundable();

            if ($amount < 1 || $amount > $payment->refundable()) {
                throw new ApiException(__('api.billing.refund_exceeds'), 422, 'refund_exceeds');
            }

            $refund = Refund::withoutGlobalScopes()->create([
                'provider_id' => $payment->provider_id,
                'payment_id' => $payment->id,
                'created_by' => $actor->id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $result = $this->stripe->createRefund($payment->provider->stripe_account_id, $payment->stripe_payment_intent_id, $amount, "refund-{$refund->id}", $reason);

            $refund->update(['stripe_refund_id' => $result['id'], 'status' => $result['status']]);

            $total = $payment->amount_refunded + $amount;
            $payment->update(['amount_refunded' => $total, 'status' => $total >= $payment->amount ? 'refunded' : 'partially_refunded']);

            activity()->performedOn($refund)->causedBy($actor)->withProperties(['payment_id' => $payment->id, 'amount' => $amount])->log('refund.created');

            return $refund;
        });
    }
}
