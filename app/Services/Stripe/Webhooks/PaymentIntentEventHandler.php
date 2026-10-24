<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Services\Dunning\DunningService;
use App\Support\Tenancy\TenantContext;

/** payment_intent.succeeded / payment_failed: one local payment per payment intent, with the reason when it failed. */
class PaymentIntentEventHandler extends ConnectEventHandler
{
    public function __construct(TenantContext $tenancy, private DunningService $dunning)
    {
        parent::__construct($tenancy);
    }

    protected function process(Provider $provider, array $object, int $at, string $type): void
    {
        $payment = Payment::firstOrNew(['stripe_payment_intent_id' => $object['id'] ?? '']);

        if ($payment->stripe_event_at !== null && $at < $payment->stripe_event_at) {
            return;
        }

        $invoice = ($invoiceId = $this->id($object['invoice'] ?? null)) ? Invoice::where('stripe_invoice_id', $invoiceId)->first() : null;

        if (! $payment->exists && ! $invoice && ! ProviderCustomer::where('stripe_customer_id', $this->id($object['customer'] ?? null))->exists()) {
            return; // not a payment for anything we track
        }

        $succeeded = $type === 'payment_intent.succeeded';
        $error = $object['last_payment_error'] ?? [];

        $payment->forceFill([
            'provider_id' => $provider->id,
            'invoice_id' => $invoice?->id ?? $payment->invoice_id,
            'stripe_invoice_id' => $this->id($object['invoice'] ?? null) ?? $payment->stripe_invoice_id,
            'subscription_id' => $invoice?->subscription_id ?? $payment->subscription_id,
            'status' => $succeeded ? 'succeeded' : 'failed',
            'amount' => $succeeded ? ($object['amount_received'] ?? $object['amount']) : $object['amount'],
            'currency' => $object['currency'],
            'stripe_charge_id' => $this->id($object['latest_charge'] ?? null) ?? $payment->stripe_charge_id,
            'failure_code' => $succeeded ? null : ($error['decline_code'] ?? $error['code'] ?? null),
            'failure_message' => $succeeded ? null : ($error['message'] ?? null),
            'paid_at' => $succeeded ? now()->setTimestamp($at) : null,
            'stripe_event_at' => $at,
        ])->save();

        if ($invoice && ! $succeeded) {
            // The invoice event may have got here first without knowing why the card failed.
            $this->dunning->reclassify($invoice, $object['id'], $payment->failure_code, $payment->failure_message);
        }
    }
}
