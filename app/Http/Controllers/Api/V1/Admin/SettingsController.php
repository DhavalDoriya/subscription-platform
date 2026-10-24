<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\Settings\SettingsService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->grouped());
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $changes = $request->changes();

        foreach ($changes as $key => $value) {
            $this->settings->set($key, $value);
        }

        if ($changes) {
            // Secret values never reach the audit log.
            $logged = collect($changes)->map(fn ($value, string $key) => $this->settings->isSecret($key) ? '[secret]' : $value)->all();
            activity()->causedBy($request->user('api'))->withProperties(['changes' => $logged])->log('settings.updated');
        }

        return ApiResponse::success($this->grouped());
    }

    /** { group: { name: { value } | { is_set } } }: secrets expose only whether they are set. */
    private function grouped(): array
    {
        $out = [];

        foreach ($this->settings->definitions() as $key => $definition) {
            [$group, $name] = explode('.', $key, 2);
            $out[$group][$name] = $this->settings->isSecret($key)
                ? ['is_set' => $this->settings->isSet($key)]
                : ['value' => $this->settings->get($key)];
        }

        return $out;
    }
}
