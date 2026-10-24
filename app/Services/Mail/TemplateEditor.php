<?php

namespace App\Services\Mail;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Saves admin edits of a template and keeps its version history (FR-E1). */
class TemplateEditor
{
    /** @param  array{subject: string, html_body: string, text_body: string, enabled: bool}  $data */
    public function update(EmailTemplate $template, array $data, User $by): EmailTemplate
    {
        return DB::transaction(function () use ($template, $data, $by) {
            $changed = $template->subject !== $data['subject'] || $template->html_body !== $data['html_body'] || $template->text_body !== $data['text_body'];

            $template->fill(['enabled' => $data['enabled'], 'updated_by' => $by->id]);

            if ($changed) {
                $template->fill([
                    'subject' => $data['subject'], 'html_body' => $data['html_body'], 'text_body' => $data['text_body'], 'version' => $template->version + 1,
                ]);
                $template->versions()->create([
                    'version' => $template->version, 'subject' => $template->subject, 'html_body' => $template->html_body,
                    'text_body' => $template->text_body, 'created_by' => $by->id,
                ]);
            }

            $template->save();

            return $template;
        });
    }

    /** Copies an older version's content forward as a new version. */
    public function restore(EmailTemplate $template, int $version, User $by): EmailTemplate
    {
        $old = $template->versions()->where('version', $version)->firstOrFail();

        return $this->update($template, [
            'subject' => $old->subject, 'html_body' => $old->html_body, 'text_body' => $old->text_body, 'enabled' => $template->enabled,
        ], $by);
    }
}
