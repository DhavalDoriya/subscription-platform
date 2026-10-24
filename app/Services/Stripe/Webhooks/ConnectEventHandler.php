<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\Provider;
use App\Support\Tenancy\TenantContext;

/**
 * Base for events that happen on a provider's connected account. Finds the provider from the event's `account` and runs
 * the handler as that tenant, so a handler can only ever read or write that provider's rows.
 */
abstract class ConnectEventHandler implements StripeEventHandler
{
    public function __construct(protected TenantContext $tenancy) {}

    public function handle(array $event): void
    {
        $account = $event['account'] ?? null;
        $provider = $account ? Provider::where('stripe_account_id', $account)->first() : null;

        if (! $provider) {
            return; // not one of ours (or not yet connected): acknowledge so Stripe does not retry
        }

        $this->tenancy->run($provider, fn () => $this->process($provider, $event['data']['object'] ?? [], (int) ($event['created'] ?? 0), $event['type']));
    }

    /** @param  array<string, mixed>  $object  the Stripe object in the event */
    abstract protected function process(Provider $provider, array $object, int $at, string $type): void;

    /** Stripe sends expandable fields either as an id string or as an object. */
    protected function id(mixed $value): ?string
    {
        return is_array($value) ? ($value['id'] ?? null) : ($value ?: null);
    }
}
