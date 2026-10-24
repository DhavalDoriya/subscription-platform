<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Models\Subscription;
use App\Services\Dunning\DunningService;
use App\Services\Stripe\StripeGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/** invoice.created/finalized/paid/payment_failed/voided/...: one local row per Stripe invoice (FR-C6). */
class InvoiceEventHandler extends ConnectEventHandler
{
    public function __construct(TenantContext $tenancy, private DunningService $dunning, private StripeGateway $stripe)
    {
        parent::__construct($tenancy);
    }

    protected function process(Provider $provider, array $object, int $at, string $type): void
    {
        $invoice = Invoice::firstOrNew(['stripe_invoice_id' => $object['id'] ?? '']);

        if ($invoice->stripe_event_at !== null && $at < $invoice->stripe_event_at) {
            return;
        }

        $subscription = Subscription::where('stripe_subscription_id', $this->id($object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null))->first();
        $link = $subscription?->provider_customer_id ?? ProviderCustomer::where('stripe_customer_id', $this->id($object['customer'] ?? null))->value('id');

        if (! $invoice->exists && ! $link) {
            return; // an invoice for a customer we never linked
        }

        $invoice->forceFill([
            'provider_id' => $provider->id,
            'subscription_id' => $subscription?->id ?? $invoice->subscription_id,
            'provider_customer_id' => $link,
            'number' => $object['number'] ?? $invoice->number,
            'status' => $object['status'] ?? $invoice->status ?? 'draft',
            'currency' => $object['currency'] ?? $invoice->currency,
            'amount_due' => $object['amount_due'] ?? 0,
            'amount_paid' => $object['amount_paid'] ?? 0,
            'hosted_invoice_url' => $object['hosted_invoice_url'] ?? $invoice->hosted_invoice_url,
            'invoice_pdf_url' => $object['invoice_pdf'] ?? $invoice->invoice_pdf_url,
            'period_start' => $this->date($object['period_start'] ?? null),
            'period_end' => $this->date($object['period_end'] ?? null),
            'due_at' => $this->date($object['due_date'] ?? null),
            'paid_at' => $this->date($object['status_transitions']['paid_at'] ?? null),
            'stripe_event_at' => $at,
        ])->save();

        // Payments that arrived before this invoice did were stored without a link.
        Payment::where('stripe_invoice_id', $invoice->stripe_invoice_id)->whereNull('invoice_id')
            ->update(['invoice_id' => $invoice->id, 'subscription_id' => $invoice->subscription_id]);

        $this->dunn($provider, $invoice, $type);
    }

    /**
     * FR-R1/R4: a failed payment on a live subscription's invoice hands the invoice to our engine and turns off Stripe's
     * own retries for it (never on `invoice.created`: a draft with auto_advance off would not be finalised or charged).
     * A paid invoice ends any dunning.
     */
    private function dunn(Provider $provider, Invoice $invoice, string $type): void
    {
        if ($type === 'invoice.paid') {
            $this->dunning->markRecovered($invoice);

            return;
        }

        if ($type !== 'invoice.payment_failed' || $invoice->status !== 'open' || ! $invoice->subscription?->status->isLive()) {
            return;
        }

        $this->stripe->updateInvoice($provider->stripe_account_id, $invoice->stripe_invoice_id, ['auto_advance' => false], "invoice-no-advance-{$invoice->id}");

        $payment = Payment::where('invoice_id', $invoice->id)->where('status', 'failed')->latest('stripe_event_at')->first();
        $this->dunning->recordFailure($invoice, $payment?->failure_code, $payment?->failure_message, $payment?->stripe_payment_intent_id);
    }

    private function date(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestampUTC($timestamp) : null;
    }
}
