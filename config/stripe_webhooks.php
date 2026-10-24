<?php

use App\Services\Stripe\Webhooks\AccountUpdatedHandler;

/*
 * Stripe event type => handler class (implements StripeEventHandler). Events without a handler are stored as
 * "ignored" and answered with 200, so Stripe does not retry them. Add one line per event as phases need it.
 */
return [
    'handlers' => [
        'account.updated' => AccountUpdatedHandler::class,
    ],
];
