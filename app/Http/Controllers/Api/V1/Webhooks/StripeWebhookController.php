<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\Webhooks\WebhookProcessor;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/** Two endpoints, two signing secrets: platform events and Connect (connected account) events. */
class StripeWebhookController extends Controller
{
    public function __construct(private SettingsService $settings, private WebhookProcessor $processor) {}

    public function platform(Request $request): JsonResponse
    {
        return $this->receive($request, 'stripe.webhook_secret');
    }

    public function connect(Request $request): JsonResponse
    {
        return $this->receive($request, 'stripe.connect_webhook_secret');
    }

    private function receive(Request $request, string $secretKey): JsonResponse
    {
        $secret = $this->settings->get($secretKey);

        if (! $secret) {
            throw new ApiException(__('api.stripe.not_configured'), 503, 'stripe_not_configured');
        }

        $payload = $request->getContent();

        try {
            Webhook::constructEvent($payload, (string) $request->header('Stripe-Signature', ''), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new ApiException(__('api.stripe.invalid_signature'), 400, 'invalid_signature');
        }

        $duplicate = $this->processor->handle(json_decode($payload, true));

        return ApiResponse::success(['received' => true] + ($duplicate ? ['duplicate' => true] : []));
    }
}
