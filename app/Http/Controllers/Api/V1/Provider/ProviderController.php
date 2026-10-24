<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\ProviderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\RegisterProviderRequest;
use App\Http\Requests\Provider\UpdateOwnProviderRequest;
use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Services\Providers\CurrentProvider;
use App\Services\Settings\SettingsService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderController extends Controller
{
    public function register(RegisterProviderRequest $request, SettingsService $settings): JsonResponse
    {
        $user = $request->user('api');

        if (ProviderUser::where('user_id', $user->id)->where('role', 'owner')->exists()) {
            throw new ApiException(__('api.providers.already_registered'), 409, 'provider_exists');
        }

        $approved = (bool) $settings->get('provider.auto_approve');

        $provider = DB::transaction(function () use ($request, $user, $settings, $approved) {
            $provider = Provider::create([
                ...$request->safe()->only(['name', 'slug', 'business_category', 'country', 'support_email']),
                'currency' => $request->validated('currency') ?? $this->defaultCurrency($settings),
                'status' => $approved ? ProviderStatus::Approved : ProviderStatus::Pending,
                'approved_at' => $approved ? now() : null,
            ]);

            $provider->members()->create(['user_id' => $user->id, 'role' => 'owner']);
            $user->assignRole('provider_owner');

            activity()->causedBy($user)->performedOn($provider)->withProperties(['status' => $provider->status->value])->log('provider.registered');

            return $provider;
        });

        return ApiResponse::created(new ProviderResource($provider));
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new ProviderResource($this->ownProvider($request)));
    }

    public function update(UpdateOwnProviderRequest $request): JsonResponse
    {
        $provider = $this->ownProvider($request);
        $before = $provider->only(['name', 'support_email', 'timezone', 'currency']) + ['settings' => $provider->settings];

        $provider->fill($request->safe()->only(['name', 'support_email', 'timezone', 'currency']));

        $settings = $provider->settings ?? [];
        foreach (['branding', 'legal'] as $group) {
            if ($request->has($group)) {
                $settings[$group] = array_merge($settings[$group] ?? [], $request->validated($group));
            }
        }
        if ($request->has('tax_enabled')) {
            $settings['tax']['enabled'] = $request->boolean('tax_enabled');
        }
        if (isset($settings['branding']['primary_color'])) {
            $settings['branding']['primary_color'] = strtolower($settings['branding']['primary_color']);
        }
        $provider->settings = $settings;

        if ($provider->isDirty()) {
            $provider->save();
            activity()->causedBy($request->user('api'))->performedOn($provider)
                ->withProperties(['from' => $before, 'to' => $provider->only(['name', 'support_email', 'timezone', 'currency']) + ['settings' => $provider->settings]])
                ->log('provider.updated');
        }

        return ApiResponse::success(new ProviderResource($provider));
    }

    private function ownProvider(Request $request): Provider
    {
        return app(CurrentProvider::class)->for($request->user('api'));
    }

    private function defaultCurrency(SettingsService $settings): string
    {
        $allowed = $settings->get('provider.allowed_currencies');
        $default = $settings->get('general.default_currency');

        return in_array($default, $allowed, true) ? $default : $allowed[0];
    }
}
