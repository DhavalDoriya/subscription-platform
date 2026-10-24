<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Reads and writes the platform settings declared in config/platform_settings.php.
 * One cached map of all rows; a write flushes it. Secrets are encrypted at rest.
 */
class SettingsService
{
    private const CACHE_KEY = 'platform.settings';

    public function get(string $key): mixed
    {
        $definition = $this->definition($key);
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : $definition['default'];
    }

    public function set(string $key, mixed $value): void
    {
        $definition = $this->definition($key);
        $secret = (bool) ($definition['secret'] ?? false);
        $json = json_encode($value);

        Setting::withTrashed()->updateOrCreate(['key' => $key], [
            'value' => $secret ? Crypt::encryptString($json) : $json,
            'is_encrypted' => $secret,
            'deleted_at' => null,
        ]);

        Cache::forget(self::CACHE_KEY);
    }

    public function isSet(string $key): bool
    {
        $this->definition($key);

        return $this->get($key) !== null && $this->get($key) !== '';
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return config('platform_settings');
    }

    public function isSecret(string $key): bool
    {
        return (bool) ($this->definitions()[$key]['secret'] ?? false);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::all()->mapWithKeys(function (Setting $setting) {
            $json = $setting->is_encrypted ? Crypt::decryptString($setting->value) : $setting->value;

            return [$setting->key => json_decode($json, true)];
        })->all());
    }

    /** @return array<string, mixed> */
    private function definition(string $key): array
    {
        return $this->definitions()[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
    }
}
