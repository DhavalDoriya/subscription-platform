<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\PaymentMethod;
use App\Models\Provider;
use App\Models\ProviderCustomer;
use App\Services\Dunning\DunningService;
use App\Support\Tenancy\TenantContext;

/** payment_method.attached/updated/detached and customer.updated (default card): saved cards mirror Stripe (FR-C7). */
class PaymentMethodEventHandler extends ConnectEventHandler
{
    public function __construct(TenantContext $tenancy, private DunningService $dunning)
    {
        parent::__construct($tenancy);
    }

    protected function process(Provider $provider, array $object, int $at, string $type): void
    {
        match ($type) {
            'payment_method.detached' => PaymentMethod::where('stripe_payment_method_id', $object['id'])->get()->each->delete(),
            'customer.updated' => $this->markDefault($object),
            default => $this->store($provider, $object),
        };
    }

    private function store(Provider $provider, array $object): void
    {
        $link = ProviderCustomer::where('stripe_customer_id', $this->id($object['customer'] ?? null))->first();

        if (! $link || ($object['type'] ?? 'card') !== 'card') {
            return;
        }

        $method = PaymentMethod::withTrashed()->firstOrNew(['stripe_payment_method_id' => $object['id']]);
        $method->forceFill([
            'provider_id' => $provider->id,
            'provider_customer_id' => $link->id,
            'brand' => $object['card']['brand'] ?? null,
            'last4' => $object['card']['last4'] ?? null,
            'exp_month' => $object['card']['exp_month'] ?? null,
            'exp_year' => $object['card']['exp_year'] ?? null,
            'deleted_at' => null,
        ])->save();
    }

    private function markDefault(array $customer): void
    {
        $link = ProviderCustomer::where('stripe_customer_id', $customer['id'] ?? '')->first();

        if (! $link) {
            return;
        }

        $default = $this->id($customer['invoice_settings']['default_payment_method'] ?? null);

        $changed = $default && ! PaymentMethod::where('provider_customer_id', $link->id)->where('stripe_payment_method_id', $default)->where('is_default', true)->exists();

        foreach (PaymentMethod::where('provider_customer_id', $link->id)->get() as $method) {
            $method->update(['is_default' => $method->stripe_payment_method_id === $default]);
        }

        if ($changed) {
            $this->dunning->retryOpenInvoices($link); // FR-R8: a new default card is a reason to try again now
        }
    }
}
