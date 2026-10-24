<?php

namespace App\Models\Concerns;

use App\Models\Provider;
use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Put on every model whose table has a `provider_id`. While a tenant is active (storefront request,
 * provider API, TenantContext::run) queries only see that provider's rows and new rows are stamped with it.
 * Add an assertTenantIsolation() test for each use (tests/Feature/Tenancy/TenantScopeTest.php).
 */
trait TenantOwned
{
    public static function bootTenantOwned(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $tenant = app(TenantContext::class)->current();

            if ($tenant) {
                $model->provider_id = $tenant->id;
            }
        });
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
