<?php

namespace App\Services\Mail;

use App\Models\EmailTemplate;

/**
 * Fills `{{placeholders}}` (FR-E2). Only the variables documented for the template's key are ever filled in; a documented
 * variable without a value and any other placeholder render as an empty string, so a bad template never breaks a send
 * and cannot leak data the sender did not mean to expose. Values are HTML-escaped in the html body only.
 */
class TemplateRenderer
{
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';

    public function __construct(private TemplateCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, html: string, text: string}
     */
    public function render(EmailTemplate $template, array $variables): array
    {
        return $this->renderContent($template->key, $template->subject, $template->html_body, $template->text_body, $variables);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, html: string, text: string}
     */
    public function renderContent(string $key, string $subject, string $html, string $text, array $variables): array
    {
        $allowed = array_keys($this->catalog->variables($key));
        $values = array_intersect_key($variables, array_flip($allowed));

        return [
            'subject' => $this->fill($subject, $values, false),
            'html' => $this->fill($html, $values, true),
            'text' => $this->fill($text, $values, false),
        ];
    }

    /** @return array<int, string> placeholders used in the texts that the key does not document */
    public function unknownVariables(string $key, string ...$texts): array
    {
        $allowed = array_keys($this->catalog->variables($key));
        $found = [];

        foreach ($texts as $text) {
            preg_match_all(self::PATTERN, $text, $matches);
            $found = [...$found, ...$matches[1]];
        }

        return array_values(array_unique(array_diff($found, $allowed)));
    }

    /** @param  array<string, mixed>  $values */
    private function fill(string $text, array $values, bool $escape): string
    {
        return preg_replace_callback(self::PATTERN, function (array $match) use ($values, $escape) {
            $value = $values[$match[1]] ?? null;
            $value = is_scalar($value) ? (string) $value : '';

            return $escape ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, $text);
    }
}
