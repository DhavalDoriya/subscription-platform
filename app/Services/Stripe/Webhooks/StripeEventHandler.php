<?php

namespace App\Services\Stripe\Webhooks;

interface StripeEventHandler
{
    /**
     * Apply one Stripe event. Must be safe to run twice (Stripe redelivers); throw to make Stripe retry.
     *
     * @param  array<string, mixed>  $event  the full event payload
     */
    public function handle(array $event): void;
}
