<?php

use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ImpersonationController as StartImpersonationController;
use App\Http\Controllers\Api\V1\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\V1\Admin\ProviderController as AdminProviderController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\StripeController as AdminStripeController;
use App\Http\Controllers\Api\V1\Admin\WebhookEventController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\ImpersonationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Provider\PlanController;
use App\Http\Controllers\Api\V1\Provider\PriceController;
use App\Http\Controllers\Api\V1\Provider\ProviderController;
use App\Http\Controllers\Api\V1\Provider\StripeConnectController;
use App\Http\Controllers\Api\V1\PublicApi\ConfigController;
use App\Http\Controllers\Api\V1\PublicApi\SlugController;
use App\Http\Controllers\Api\V1\PublicApi\TenantController;
use App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Every route here is served under /api (framework prefix). Groups per audience are added
// phase by phase: public, customer, provider, admin.
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);

    // Stripe calls these with a signature, not a login; the controller verifies it.
    Route::post('webhooks/stripe', [StripeWebhookController::class, 'platform']);
    Route::post('webhooks/stripe/connect', [StripeWebhookController::class, 'connect']);

    Route::prefix('public')->group(function () {
        Route::get('slugs/{slug}/availability', [SlugController::class, 'availability'])->middleware('throttle:60,1');
        Route::get('config', [ConfigController::class, 'show']);
        Route::get('tenant', [TenantController::class, 'show'])->middleware('tenant');
    });

    Route::prefix('provider')->middleware('auth:api')->group(function () {
        Route::post('register', [ProviderController::class, 'register'])->middleware('throttle:10,1');
        Route::get('/', [ProviderController::class, 'show']);
        Route::patch('/', [ProviderController::class, 'update']);
        Route::post('stripe/onboarding', [StripeConnectController::class, 'onboarding']);
        Route::get('stripe', [StripeConnectController::class, 'show']);
        Route::post('stripe/dashboard', [StripeConnectController::class, 'dashboard']);

        Route::middleware('provider.tenant')->group(function () {
            Route::get('plans', [PlanController::class, 'index']);
            Route::post('plans', [PlanController::class, 'store']);
            Route::get('plans/{plan}', [PlanController::class, 'show']);
            Route::patch('plans/{plan}', [PlanController::class, 'update']);
            Route::delete('plans/{plan}', [PlanController::class, 'destroy']);
            Route::post('plans/{plan}/publish', [PlanController::class, 'publish']);
            Route::post('plans/{plan}/archive', [PlanController::class, 'archive']);
            Route::post('plans/{plan}/prices', [PriceController::class, 'store']);
            Route::patch('plans/{plan}/prices/{price}', [PriceController::class, 'update']);
            Route::delete('plans/{plan}/prices/{price}', [PriceController::class, 'destroy']);
        });
    });

    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('refresh', [AuthController::class, 'refresh']);
            Route::post('password/forgot', [PasswordController::class, 'forgot']);
            Route::post('password/reset', [PasswordController::class, 'reset']);
        });

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')->name('verification.verify');

        Route::middleware('auth:api')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('impersonation/end', [ImpersonationController::class, 'end']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
            Route::patch('profile', [ProfileController::class, 'update']);
            Route::put('password', [ProfileController::class, 'password'])->middleware('throttle:6,1');
            Route::post('avatar', [ProfileController::class, 'storeAvatar']);
            Route::delete('avatar', [ProfileController::class, 'destroyAvatar']);
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1');
        });
    });

    Route::prefix('admin')->middleware(['auth:api', 'role:admin|super_admin,api'])->group(function () {
        Route::get('settings', [SettingsController::class, 'index']);
        Route::put('settings', [SettingsController::class, 'update']);

        Route::get('providers', [AdminProviderController::class, 'index']);
        Route::get('providers/{provider}', [AdminProviderController::class, 'show']);
        Route::patch('providers/{provider}', [AdminProviderController::class, 'update']);
        foreach (['approve', 'reject', 'activate', 'deactivate', 'suspend'] as $action) {
            Route::post("providers/{provider}/{$action}", [AdminProviderController::class, $action]);
        }

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('dashboard', [DashboardController::class, 'show']);
        Route::get('stripe', [AdminStripeController::class, 'show']);

        Route::get('webhook-events', [WebhookEventController::class, 'index']);
        Route::get('webhook-events/{event}', [WebhookEventController::class, 'show'])->name('admin.webhook-events.show');
        Route::post('webhook-events/{event}/replay', [WebhookEventController::class, 'replay']);

        Route::get('plans', [AdminPlanController::class, 'index']);
        Route::post('plans/{plan}/archive', [AdminPlanController::class, 'archive']);

        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);
        Route::post('customers/{customer}/block', [CustomerController::class, 'block']);
        Route::post('customers/{customer}/unblock', [CustomerController::class, 'unblock']);

        Route::post('users/{user}/impersonate', [StartImpersonationController::class, 'start']);

        Route::middleware('role:super_admin,api')->group(function () {
            Route::get('admins', [AdminUserController::class, 'index']);
            Route::post('admins', [AdminUserController::class, 'store']);
            Route::delete('admins/{admin}', [AdminUserController::class, 'destroy']);
        });
    });
});
