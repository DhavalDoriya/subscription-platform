<?php

namespace App\Services\Mail;

use App\Models\EmailTemplate;
use InvalidArgumentException;

/**
 * The default template set and the documented placeholders (config/email_templates.php). The database copy is what gets
 * sent; this catalog only creates it (once) and says which variables each key may use (FR-E2, FR-E3).
 */
class TemplateCatalog
{
    /** @return array<string, array<string, mixed>> key => definition (keys contain dots, so never read them through a dotted config path) */
    private function templates(): array
    {
        return config('email_templates.templates');
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->templates());
    }

    public function has(string $key): bool
    {
        return isset($this->templates()[$key]);
    }

    /** @return array<string, string> placeholder => description */
    public function variables(string $key): array
    {
        $names = $this->templates()[$key]['variables'] ?? [];

        return collect($names)->mapWithKeys(fn (string $name) => [$name => config('email_templates.variables')[$name][0] ?? ''])->all();
    }

    /** @return array<string, string> placeholder => sample value for preview and test-send */
    public function samples(string $key): array
    {
        $names = $this->templates()[$key]['variables'] ?? [];

        return collect($names)->mapWithKeys(fn (string $name) => [$name => config('email_templates.variables')[$name][1] ?? ''])->all();
    }

    /** The template row for a key, created from its default the first time it is needed. */
    public function ensure(string $key): EmailTemplate
    {
        $existing = EmailTemplate::where('key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $definition = $this->templates()[$key] ?? throw new InvalidArgumentException("Unknown email template [{$key}].");

        $template = EmailTemplate::create([
            'key' => $key,
            'name' => $definition['name'],
            'description' => $definition['description'] ?? null,
            'subject' => $definition['subject'],
            'html_body' => $this->html($definition),
            'text_body' => $this->text($definition),
            'enabled' => true,
            'version' => 1,
        ]);
        $template->versions()->create([
            'version' => 1, 'subject' => $template->subject, 'html_body' => $template->html_body, 'text_body' => $template->text_body,
        ]);

        return $template;
    }

    /** Creates every missing default; existing (possibly edited) templates are left alone. */
    public function seedAll(): void
    {
        foreach ($this->keys() as $key) {
            $this->ensure($key);
        }
    }

    /** @param  array<string, mixed>  $definition */
    private function html(array $definition): string
    {
        $paragraphs = array_map(fn (string $line) => '<p>'.$line.'</p>', $definition['body']);

        if (isset($definition['cta'])) {
            [$label, $variable] = $definition['cta'];
            $paragraphs[] = '<p><a href="{{'.$variable.'}}">'.$label.'</a></p>';
        }

        return implode("\n", $paragraphs);
    }

    /** @param  array<string, mixed>  $definition */
    private function text(array $definition): string
    {
        $lines = $definition['body'];

        if (isset($definition['cta'])) {
            [$label, $variable] = $definition['cta'];
            $lines[] = $label.': {{'.$variable.'}}';
        }

        return implode("\n\n", $lines);
    }
}
