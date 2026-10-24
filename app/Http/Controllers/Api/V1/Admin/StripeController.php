<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class StripeController extends Controller
{
    /** What an admin needs to wire Stripe up: is it configured, test or live, and which URLs to register. Never a key. */
    public function show(SettingsService $settings): JsonResponse
    {
        $key = (string) $settings->get('stripe.secret_key');

        return ApiResponse::success([
            'configured' => $key !== '',
            'mode' => match (true) {
                str_contains($key, '_test_') => 'test',
                str_contains($key, '_live_') => 'live',
                default => null,
            },
            'webhook_url' => url('/api/v1/webhooks/stripe'),
            'connect_webhook_url' => url('/api/v1/webhooks/stripe/connect'),
            'webhook_secret_set' => $settings->isSet('stripe.webhook_secret'),
            'connect_webhook_secret_set' => $settings->isSet('stripe.connect_webhook_secret'),
        ]);
    }
}
