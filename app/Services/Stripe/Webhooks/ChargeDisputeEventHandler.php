<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Provider;
use App\Services\Commission\CommissionRecorder;
use App\Services\Stripe\StripeGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * charge.dispute.created / updated / closed: mirrors the chargeback on the payment. A lost dispute gives the platform
 * fee back to the provider, in our records and (as an application-fee refund) at Stripe.
 */
class ChargeDisputeEventHandler extends ConnectEventHandler
{
    public function __construct(TenantContext $tenancy, private CommissionRecorder $commissions, private StripeGateway $stripe)
    {
        parent::__construct($tenancy);
    }

    protected function process(Provider $provider, array $object, int $at, string $type): void
    {
        $payment = Payment::where('stripe_charge_id', $this->id($object['charge'] ?? null) ?? '')->first()
            ?? Payment::where('stripe_payment_intent_id', $this->id($object['payment_intent'] ?? null) ?? '')->first();

        if (! $payment) {
            return; // a payment we never recorded
        }

        $dispute = Dispute::firstOrNew(['stripe_dispute_id' => $object['id'] ?? '']);

        if ($dispute->stripe_event_at !== null && $at < $dispute->stripe_event_at) {
            return;
        }

        $dispute->forceFill([
            'provider_id' => $provider->id,
            'payment_id' => $payment->id,
            'amount' => $object['amount'] ?? $dispute->amount,
            'currency' => $object['currency'] ?? $payment->currency,
            'reason' => $object['reason'] ?? $dispute->reason,
            'status' => $object['status'] ?? $dispute->status,
            'evidence_due_by' => isset($object['evidence_details']['due_by']) ? Carbon::createFromTimestampUTC($object['evidence_details']['due_by']) : $dispute->evidence_due_by,
            'stripe_event_at' => $at,
        ])->save();

        if ($dispute->status === 'lost' && $this->commissions->reverseForLostDispute($payment)) {
            $this->stripe->refundApplicationFeeForCharge($payment->stripe_charge_id, "dispute-{$dispute->stripe_dispute_id}-fee");
        }
    }
}
