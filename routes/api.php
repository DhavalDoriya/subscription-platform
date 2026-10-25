<?php

use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Api\V1\Admin\CommissionController as AdminCommissionController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DunningController as AdminDunningController;
use App\Http\Controllers\Api\V1\Admin\EmailLogController;
use App\Http\Controllers\Api\V1\Admin\EmailTemplateController;
use App\Http\Controllers\Api\V1\Admin\ImpersonationController as StartImpersonationController;
use App\Http\Controllers\Api\V1\Admin\MailSettingsController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
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
use App\Http\Controllers\Api\V1\Customer\AccessController;
use App\Http\Controllers\Api\V1\Customer\CheckoutController;
use App\Http\Controllers\Api\V1\Customer\PaymentMethodController;
use App\Http\Controllers\Api\V1\Customer\PortalController;
use App\Http\Controllers\Api\V1\Customer\SubscriptionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Provider\BillingController;
use App\Http\Controllers\Api\V1\Provider\DunningController as ProviderDunningController;
use App\Http\Controllers\Api\V1\Provider\MediaController;
use App\Http\Controllers\Api\V1\Provider\PaymentController;
use App\Http\Controllers\Api\V1\Provider\PlanController;
use App\Http\Controllers\Api\V1\Provider\PriceController;
use App\Http\Controllers\Api\V1\Provider\ProviderController;
use App\Http\Controllers\Api\V1\Provider\StorefrontController;
use App\Http\Controllers\Api\V1\Provider\StripeConnectController;
use App\Http\Controllers\Api\V1\Provider\SubscriberController;
use App\Http\Controllers\Api\V1\Provider\SubscriptionController as ProviderSubscriptionController;
use App\Http\Controllers\Api\V1\PublicApi\ConfigController;
use App\Http\Controllers\Api\V1\PublicApi\SlugController;
use App\Http\Controllers\Api\V1\PublicApi\StorefrontController as PublicStorefrontController;
use App\Http\Controllers\Api\V1\PublicApi\TenantController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Every route here is served under /api (framework prefix). Groups per audience are added
// phase by phase: public, customer, provider, admin.
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);
    Route::get('health/ready', [HealthController::class, 'ready']);

    // Stripe calls these with a signature, not a login; the controller verifies it.
    Route::post('webhooks/stripe', [StripeWebhookController::class, 'platform']);
    Route::post('webhooks/stripe/connect', [StripeWebhookController::class, 'connect']);

    Route::prefix('public')->middleware('throttle:public')->group(function () {
        Route::get('slugs/{slug}/availability', [SlugController::class, 'availability'])->middleware('throttle:60,1');
        Route::get('config', [ConfigController::class, 'show']);
        Route::get('storefront', [PublicStorefrontController::class, 'show'])->middleware('tenant');
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
            Route::get('storefront', [StorefrontController::class, 'show']);
            Route::put('storefront', [StorefrontController::class, 'update']);
            Route::post('storefront/publish', [StorefrontController::class, 'publish']);
            Route::post('storefront/unpublish', [StorefrontController::class, 'unpublish']);
            Route::post('storefront/preview', [StorefrontController::class, 'preview']);

            Route::get('media', [MediaController::class, 'index']);
            Route::post('media', [MediaController::class, 'store'])->middleware('throttle:60,1');
            Route::patch('media/{media}', [MediaController::class, 'update']);
            Route::delete('media/{media}', [MediaController::class, 'destroy']);

            Route::get('overview', [BillingController::class, 'overview']);
            Route::get('subscribers', [SubscriberController::class, 'index']);
            Route::get('subscriptions', [ProviderSubscriptionController::class, 'index']);
            Route::get('subscriptions/{subscription}', [ProviderSubscriptionController::class, 'show']);
            Route::post('subscriptions/{subscription}/cancel', [ProviderSubscriptionController::class, 'cancel']);
            Route::get('invoices', [BillingController::class, 'invoices']);
            Route::get('invoices/{invoice}/dunning', [ProviderDunningController::class, 'show']);
            Route::post('invoices/{invoice}/retry', [ProviderDunningController::class, 'retry']);
            Route::post('invoices/{invoice}/resolve', [ProviderDunningController::class, 'resolve']);
            Route::prefix('reports')->controller(ReportController::class)->group(function () {
                Route::get('revenue', 'revenue');
                Route::get('churn', 'churn');
                Route::get('failures', 'failures');
                Route::get('export', 'export');
            });

            Route::get('payments', [BillingController::class, 'payments']);
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);

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

    // Customer area: acts for the signed-in user. `tenant` adds the provider when called from a storefront host.
    Route::prefix('customer')->middleware(['auth:api', 'tenant'])->group(function () {
        Route::get('subscriptions', [PortalController::class, 'subscriptions']);
        Route::get('invoices', [PortalController::class, 'invoices']);
        Route::get('payment-issues', [PortalController::class, 'paymentIssues']);
        Route::get('payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('payment-methods/setup-intent', [PaymentMethodController::class, 'setupIntent']);
        Route::post('payment-methods/{method}/default', [PaymentMethodController::class, 'makeDefault']);
        Route::delete('payment-methods/{method}', [PaymentMethodController::class, 'destroy']);
        Route::post('subscriptions', [CheckoutController::class, 'store'])->middleware('throttle:20,1');
        Route::get('subscriptions/{subscription}/options', [SubscriptionController::class, 'options']);
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume']);
        Route::post('subscriptions/{subscription}/change/preview', [SubscriptionController::class, 'previewChange']);
        Route::post('subscriptions/{subscription}/change', [SubscriptionController::class, 'change']);
    });

    Route::get('subscriptions/{subscription}/access', [AccessController::class, 'show'])->middleware('auth:api');

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

        Route::post('mail/test', [MailSettingsController::class, 'test']);
        Route::get('email-templates', [EmailTemplateController::class, 'index']);
        Route::get('email-templates/{template}', [EmailTemplateController::class, 'show']);
        Route::put('email-templates/{template}', [EmailTemplateController::class, 'update']);
        Route::get('email-templates/{template}/versions', [EmailTemplateController::class, 'versions']);
        Route::post('email-templates/{template}/versions/{version}/restore', [EmailTemplateController::class, 'restore'])->whereNumber('version');
        Route::post('email-templates/{template}/preview', [EmailTemplateController::class, 'preview']);
        Route::post('email-templates/{template}/test', [EmailTemplateController::class, 'test']);
        Route::get('email-logs', [EmailLogController::class, 'index']);
        Route::get('email-logs/{log}', [EmailLogController::class, 'show']);
        Route::post('email-logs/{log}/resend', [EmailLogController::class, 'resend']);

        Route::get('providers', [AdminProviderController::class, 'index']);
        Route::get('providers/{provider}', [AdminProviderController::class, 'show']);
        Route::patch('providers/{provider}', [AdminProviderController::class, 'update']);
        Route::post('providers/{provider}/storefront/unpublish', [AdminProviderController::class, 'unpublishStorefront']);
        foreach (['approve', 'reject', 'activate', 'deactivate', 'suspend'] as $action) {
            Route::post("providers/{provider}/{$action}", [AdminProviderController::class, $action]);
        }

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('dashboard', [DashboardController::class, 'show']);
        Route::get('stripe', [AdminStripeController::class, 'show']);

        Route::get('webhook-events', [WebhookEventController::class, 'index']);
        Route::get('webhook-events/{event}', [WebhookEventController::class, 'show'])->name('admin.webhook-events.show');
        Route::post('webhook-events/{event}/replay', [WebhookEventController::class, 'replay']);

        Route::prefix('reports')->controller(ReportController::class)->group(function () {
            Route::get('revenue', 'revenue');
            Route::get('churn', 'churn');
            Route::get('failures', 'failures');
            Route::get('export', 'export');
        });

        Route::get('commission/rules', [AdminCommissionController::class, 'rules']);
        Route::post('commission/rules', [AdminCommissionController::class, 'store']);
        Route::delete('commission/rules/{rule}', [AdminCommissionController::class, 'destroy']);
        Route::get('commission/report', [AdminCommissionController::class, 'report']);

        Route::get('plans', [AdminPlanController::class, 'index']);
        Route::post('plans/{plan}/archive', [AdminPlanController::class, 'archive']);

        Route::get('subscriptions', [AdminBillingController::class, 'subscriptions']);
        Route::post('subscriptions/{subscription}/cancel', [AdminBillingController::class, 'cancel']);
        Route::get('invoices', [AdminBillingController::class, 'invoices']);
        Route::get('invoices/{invoice}/dunning', [AdminDunningController::class, 'show']);
        Route::post('invoices/{invoice}/retry', [AdminDunningController::class, 'retry']);
        Route::post('invoices/{invoice}/resolve', [AdminDunningController::class, 'resolve']);
        Route::post('payments/{payment}/refund', [AdminPaymentController::class, 'refund']);

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
