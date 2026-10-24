<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Body: { settings: { <group>: { <name>: value } } }. Unknown keys are rejected; secrets need a super admin
 * and a null secret means "leave unchanged".
 */
class UpdateSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $settings = app(SettingsService::class);
        $touchesSecret = collect(Arr::dot($this->input('settings', [])))
            ->contains(fn ($value, $key) => $settings->isSecret($key) && $value !== null);

        return ! $touchesSecret || $this->user('api')->hasRole('super_admin');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach (app(SettingsService::class)->definitions() as $key => $definition) {
            $rules["settings.{$key}"] = $definition['rules'] ?? match ($definition['type']) {
                'bool' => ['boolean'],
                'int' => ['integer', 'min:0'],
                'list' => ['array'],
                default => ['string', 'max:255'],
            };

            if (($definition['type'] ?? null) === 'list') {
                $rules["settings.{$key}.*"] = $definition['item_rules'] ?? ['string', 'max:64'];
            }

            array_unshift($rules["settings.{$key}"], 'sometimes');
            if (! empty($definition['secret']) || in_array('nullable', $rules["settings.{$key}"], true)) {
                $rules["settings.{$key}"][] = 'nullable';
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $known = array_keys(app(SettingsService::class)->definitions());

            foreach (array_keys(Arr::dot($this->input('settings', []))) as $key) {
                $listItem = preg_replace('/\.\d+$/', '', $key);
                if (! in_array($key, $known, true) && ! in_array($listItem, $known, true)) {
                    $validator->errors()->add("settings.{$key}", __('api.settings.unknown_key'));
                }
            }
        }];
    }

    /** @return array<string, mixed> flat map of key => value, secrets left unchanged when null */
    public function changes(): array
    {
        $settings = app(SettingsService::class);

        return collect($settings->definitions())->keys()
            ->filter(fn (string $key) => Arr::has($this->input('settings', []), $key))
            ->mapWithKeys(fn (string $key) => [$key => Arr::get($this->input('settings', []), $key)])
            ->reject(fn ($value, string $key) => $settings->isSecret($key) && $value === null)
            ->all();
    }
}
