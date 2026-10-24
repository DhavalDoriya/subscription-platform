<?php

namespace App\Support\Tenancy;

use App\Models\Provider;
use Closure;

/** The provider the current request or job acts for. Bound as a singleton; null means platform scope (admin, system). */
class TenantContext
{
    private ?Provider $provider = null;

    public function current(): ?Provider
    {
        return $this->provider;
    }

    public function set(Provider $provider): void
    {
        $this->provider = $provider;
    }

    public function forget(): void
    {
        $this->provider = null;
    }

    /** Runs the callback as the given tenant and restores the previous one afterwards. */
    public function run(Provider $provider, Closure $callback): mixed
    {
        $previous = $this->provider;
        $this->provider = $provider;

        try {
            return $callback();
        } finally {
            $this->provider = $previous;
        }
    }
}
