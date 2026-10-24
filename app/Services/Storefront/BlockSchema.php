<?php

namespace App\Services\Storefront;

use App\Support\Html\RichText;

/**
 * What each storefront block type may contain (FR-S2). One definition drives validation and sanitising, so a new block
 * type is one entry here plus a renderer in the SPA. `html` lists the rich-text fields that must pass RichText::clean.
 */
final class BlockSchema
{
    private const URL = ['nullable', 'url:http,https', 'max:2048'];

    /** @return array<string, array{rules: array<string, array<int, string>>, html: array<int, string>}> */
    public static function definitions(): array
    {
        return [
            'hero' => ['html' => [], 'rules' => [
                'headline' => ['required', 'string', 'max:120'],
                'subheadline' => ['nullable', 'string', 'max:300'],
                'button_label' => ['nullable', 'string', 'max:40'],
                'button_url' => self::URL,
                'image_url' => self::URL,
            ]],
            'about' => ['html' => ['body'], 'rules' => [
                'heading' => ['nullable', 'string', 'max:120'],
                'body' => ['required', 'string', 'max:10000'],
            ]],
            'text' => ['html' => ['body'], 'rules' => [
                'body' => ['required', 'string', 'max:10000'],
            ]],
            'image' => ['html' => [], 'rules' => [
                'image_url' => ['required', 'url:http,https', 'max:2048'],
                'alt' => ['required', 'string', 'max:200'],
                'caption' => ['nullable', 'string', 'max:300'],
            ]],
            'feature_list' => ['html' => [], 'rules' => [
                'heading' => ['nullable', 'string', 'max:120'],
                'items' => ['required', 'array', 'min:1', 'max:12'],
                'items.*.title' => ['required', 'string', 'max:80'],
                'items.*.description' => ['nullable', 'string', 'max:300'],
            ]],
            'testimonials' => ['html' => [], 'rules' => [
                'heading' => ['nullable', 'string', 'max:120'],
                'items' => ['required', 'array', 'min:1', 'max:12'],
                'items.*.quote' => ['required', 'string', 'max:500'],
                'items.*.author' => ['required', 'string', 'max:80'],
                'items.*.role' => ['nullable', 'string', 'max:80'],
            ]],
            'faq' => ['html' => ['items.*.answer'], 'rules' => [
                'heading' => ['nullable', 'string', 'max:120'],
                'items' => ['required', 'array', 'min:1', 'max:20'],
                'items.*.question' => ['required', 'string', 'max:200'],
                'items.*.answer' => ['required', 'string', 'max:2000'],
            ]],
            'cta' => ['html' => [], 'rules' => [
                'heading' => ['required', 'string', 'max:120'],
                'text' => ['nullable', 'string', 'max:300'],
                'button_label' => ['required', 'string', 'max:40'],
                'button_url' => ['required', 'url:http,https', 'max:2048'],
            ]],
            'plans' => ['html' => [], 'rules' => [
                'heading' => ['nullable', 'string', 'max:120'],
                'subheading' => ['nullable', 'string', 'max:300'],
            ]],
            'contact' => ['html' => [], 'rules' => [
                'heading' => ['nullable', 'string', 'max:120'],
                'body' => ['nullable', 'string', 'max:1000'],
                'email' => ['nullable', 'email:rfc', 'max:255'],
            ]],
        ];
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return array_keys(self::definitions());
    }

    /** @return array<string, array<int, string>> rules for a block's `data`, keyed by field path */
    public static function rules(string $type): array
    {
        return self::definitions()[$type]['rules'] ?? [];
    }

    /**
     * Drops fields the type does not define (no smuggling extra keys into stored content) and cleans rich text.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(string $type, array $data): array
    {
        $definition = self::definitions()[$type];
        $allowed = array_unique(array_map(fn (string $path) => explode('.', $path)[0], array_keys($definition['rules'])));
        $data = array_intersect_key($data, array_flip($allowed));

        foreach ($definition['html'] as $path) {
            self::cleanPath($data, explode('.', $path));
        }

        // Per-item fields are whitelisted too.
        foreach (['items'] as $list) {
            if (isset($data[$list]) && is_array($data[$list])) {
                $itemKeys = array_unique(array_map(fn (string $path) => explode('.', $path)[2], array_filter(array_keys($definition['rules']), fn (string $p) => str_starts_with($p, "{$list}.*."))));
                $data[$list] = array_values(array_map(fn ($item) => is_array($item) ? array_intersect_key($item, array_flip($itemKeys)) : [], $data[$list]));
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $node */
    private static function cleanPath(array &$node, array $segments): void
    {
        $head = array_shift($segments);

        if ($head === '*') {
            foreach ($node as &$child) {
                if (is_array($child)) {
                    self::cleanPath($child, $segments);
                }
            }

            return;
        }

        if (! array_key_exists($head, $node)) {
            return;
        }

        if ($segments === []) {
            $node[$head] = is_string($node[$head]) ? RichText::clean($node[$head]) : $node[$head];

            return;
        }

        if (is_array($node[$head])) {
            self::cleanPath($node[$head], $segments);
        }
    }
}
