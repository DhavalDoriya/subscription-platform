<?php

namespace App\Services\Storefront;

use App\Models\PageBlock;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Provider;
use App\Models\StorefrontPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the cached public storefront honest (FR-L2): saving or deleting anything the public view is built from clears
 * that provider's entry, so a status change, a published edit or a plan change shows at once instead of after the TTL.
 */
class StorefrontCache
{
    /** The models the public view reads. */
    private const SOURCES = [Provider::class, Plan::class, Price::class, StorefrontPage::class, PageBlock::class];

    public static function key(string $providerId): string
    {
        return "storefront:{$providerId}";
    }

    public static function forget(string $providerId): void
    {
        Cache::forget(self::key($providerId));
    }

    public static function register(): void
    {
        foreach (self::SOURCES as $model) {
            $clear = function (Model $row): void {
                $providerId = $row instanceof Provider ? $row->id : $row->provider_id;
                if ($providerId) {
                    self::forget($providerId);
                }
            };
            $model::saved($clear);
            $model::deleted($clear);
        }
    }
}
