<?php

namespace App\Services\Stripe;

/** Turns a raw Stripe subscription (SDK object, array or webhook payload) into the few facts we mirror locally. */
final class StripeSubscriptionState
{
    /**
     * @return array{id: string, customer_id: ?string, status: string, price_id: ?string, item_id: ?string, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, cancel_at_period_end: bool, canceled_at: ?int, ended_at: ?int, paused: bool, latest_invoice_id: ?string, client_secret: ?string, client_secret_type: ?string}
     */
    public static function normalize(mixed $subscription): array
    {
        $sub = json_decode((string) json_encode($subscription), true) ?? [];
        $item = $sub['items']['data'][0] ?? [];
        $invoice = is_array($sub['latest_invoice'] ?? null) ? $sub['latest_invoice'] : [];

        // A trial has nothing to pay yet, so Stripe hands out a SetupIntent instead of a payment secret.
        $paymentSecret = $invoice['confirmation_secret']['client_secret'] ?? null;
        $setupSecret = is_array($sub['pending_setup_intent'] ?? null) ? ($sub['pending_setup_intent']['client_secret'] ?? null) : null;

        return [
            'id' => (string) ($sub['id'] ?? ''),
            'customer_id' => is_string($sub['customer'] ?? null) ? $sub['customer'] : ($sub['customer']['id'] ?? null),
            'status' => (string) ($sub['status'] ?? 'incomplete'),
            'price_id' => $item['price']['id'] ?? null,
            'item_id' => $item['id'] ?? null,
            // Newer API versions report the billing period on the item instead of the subscription.
            'current_period_start' => $sub['current_period_start'] ?? $item['current_period_start'] ?? null,
            'current_period_end' => $sub['current_period_end'] ?? $item['current_period_end'] ?? null,
            'trial_end' => $sub['trial_end'] ?? null,
            'cancel_at_period_end' => (bool) ($sub['cancel_at_period_end'] ?? false),
            'canceled_at' => $sub['canceled_at'] ?? null,
            'ended_at' => $sub['ended_at'] ?? null,
            'paused' => ! empty($sub['pause_collection']),
            'latest_invoice_id' => $invoice['id'] ?? (is_string($sub['latest_invoice'] ?? null) ? $sub['latest_invoice'] : null),
            'client_secret' => $paymentSecret ?? $setupSecret,
            'client_secret_type' => $paymentSecret ? 'payment' : ($setupSecret ? 'setup' : null),
        ];
    }
}
