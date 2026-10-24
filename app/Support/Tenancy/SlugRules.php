<?php

namespace App\Support\Tenancy;

use App\Models\Provider;
use App\Services\Settings\SettingsService;

/** Single source of truth for provider slugs (PRD FR-P3). `check()` returns null when free, else a reason. */
class SlugRules
{
    public const INVALID = 'invalid';

    public const RESERVED = 'reserved';

    public const TAKEN = 'taken';

    /** Subdomains that must never belong to a provider. */
    public const BUILT_IN_RESERVED = [
        'www', 'admin', 'api', 'app', 'mail', 'dashboard', 'account', 'static', 'assets', 'cdn', 'docs', 'help', 'status',
        'support', 'billing', 'login', 'register', 'webhooks', 'stripe', 'platform', 'root', 'smtp', 'ftp', 'ns1', 'ns2',
    ];

    public function __construct(private SettingsService $settings) {}

    public function check(string $slug): ?string
    {
        if (! preg_match('/^[a-z0-9](?!.*--)[a-z0-9-]{1,38}[a-z0-9]$/', $slug)) {
            return self::INVALID;
        }

        if ($this->isReserved($slug)) {
            return self::RESERVED;
        }

        return Provider::withTrashed()->where('slug', $slug)->exists() ? self::TAKEN : null;
    }

    public function isReserved(string $slug): bool
    {
        return in_array($slug, [...self::BUILT_IN_RESERVED, ...$this->settings->get('provider.reserved_slugs')], true);
    }
}
