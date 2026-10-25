<?php

use App\Services\Stripe\Webhooks\AccountUpdatedHandler;
use App\Services\Stripe\Webhooks\ChargeDisputeEventHandler;
use App\Services\Stripe\Webhooks\ChargeRefundedEventHandler;
use App\Services\Stripe\Webhooks\InvoiceEventHandler;
use App\Services\Stripe\Webhooks\PaymentIntentEventHandler;
use App\Services\Stripe\Webhooks\PaymentMethodEventHandler;
use App\Services\Stripe\Webhooks\SubscriptionEventHandler;

/*
 * Stripe event type => handler class (implements StripeEventHandler). Events without a handler are stored as
 * "ignored" and answered with 200, so Stripe does not retry them. Add one line per event as phases need it.
 */
return [
    'handlers' => [
        'account.updated' => AccountUpdatedHandler::class,

        'customer.subscription.created' => SubscriptionEventHandler::class,
        'customer.subscription.updated' => SubscriptionEventHandler::class,
        'customer.subscription.deleted' => SubscriptionEventHandler::class,

        'invoice.created' => InvoiceEventHandler::class,
        'invoice.finalized' => InvoiceEventHandler::class,
        'invoice.updated' => InvoiceEventHandler::class,
        'invoice.paid' => InvoiceEventHandler::class,
        'invoice.payment_failed' => InvoiceEventHandler::class,
        'invoice.voided' => InvoiceEventHandler::class,
        'invoice.marked_uncollectible' => InvoiceEventHandler::class,

        'payment_intent.succeeded' => PaymentIntentEventHandler::class,
        'payment_intent.payment_failed' => PaymentIntentEventHandler::class,
        'charge.refunded' => ChargeRefundedEventHandler::class,
        'charge.dispute.created' => ChargeDisputeEventHandler::class,
        'charge.dispute.updated' => ChargeDisputeEventHandler::class,
        'charge.dispute.closed' => ChargeDisputeEventHandler::class,

        'payment_method.attached' => PaymentMethodEventHandler::class,
        'payment_method.updated' => PaymentMethodEventHandler::class,
        'payment_method.detached' => PaymentMethodEventHandler::class,
        'customer.updated' => PaymentMethodEventHandler::class,
    ],
];
