<?php

namespace App\Http\Requests\Provider;

use App\Http\Requests\ApiFormRequest;
use App\Services\Storefront\BlockSchema;
use Illuminate\Validation\Rule;

/** The whole draft in one document: seo plus the ordered list of blocks. Each block's data is validated by its type. */
class SaveStorefrontRequest extends ApiFormRequest
{
    public const MAX_BLOCKS = 30;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:160'],
            'seo.og_image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'blocks' => ['present', 'array', 'max:'.self::MAX_BLOCKS],
            'blocks.*.id' => ['nullable', 'string', 'max:64'],
            'blocks.*.type' => ['required', Rule::in(BlockSchema::types())],
            'blocks.*.visible' => ['nullable', 'boolean'],
            'blocks.*.data' => ['present', 'array'],
        ];

        foreach ((array) $this->input('blocks', []) as $index => $block) {
            if (is_array($block) && in_array($block['type'] ?? null, BlockSchema::types(), true)) {
                foreach (BlockSchema::rules($block['type']) as $field => $fieldRules) {
                    $rules["blocks.{$index}.data.{$field}"] = $fieldRules;
                }
            }
        }

        return $rules;
    }
}
