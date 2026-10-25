<?php

namespace App\Providers;

use App\Services\Mail\DatabaseTemplateMail;
use App\Services\Mail\TemplateMail;
use App\Services\Storefront\StorefrontCache;
use App\Services\Stripe\StripeApi;
use App\Services\Stripe\StripeGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TemplateMail::class, DatabaseTemplateMail::class);
        $this->app->singleton(StripeGateway::class, fn () => new StripeApi(StripeApi::clientFromSettings(...)));
    }

    public function boot(): void
    {
        StorefrontCache::register();

        // The API docs describe every route, so outside local development they are off unless API_DOCS=true (staging).
        Gate::define('viewApiDocs', fn ($user = null) => ! $this->app->isProduction() || (bool) config('app.api_docs'));

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(config('auth.rate_limits.public'))->by($request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(config('auth.rate_limits.auth'))->by($request->ip()));

        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->when(
            $this->app->isProduction(),
            fn (Password $rule) => $rule->uncompromised(),
        ));
    }
}
