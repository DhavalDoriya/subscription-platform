<?php

namespace App\Http\Controllers\Api\V1\PublicApi;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    /** The few platform settings a signed-out visitor or a signup form needs. Never add anything sensitive here. */
    public function show(SettingsService $settings): JsonResponse
    {
        return ApiResponse::success([
            'platform_name' => $settings->get('general.platform_name'),
            'default_currency' => $settings->get('general.default_currency'),
            'allowed_currencies' => $settings->get('provider.allowed_currencies'),
            'base_domain' => config('app.base_domain'),
            'allowed_intervals' => $settings->get('provider.allowed_intervals'),
            'min_price' => $settings->get('provider.min_price'),
            'max_price' => $settings->get('provider.max_price'),
            'tax_available' => (bool) $settings->get('stripe.tax_enabled'),
        ]);
    }
}
