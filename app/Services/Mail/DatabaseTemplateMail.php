<?php

namespace App\Services\Mail;

use App\Models\EmailLog;
use App\Models\EmailTemplate;

/**
 * The real TemplateMail: renders the admin-editable template from the database, writes the delivery log row and queues
 * the send (FR-E1, FR-E6). A disabled template is logged as skipped and not sent.
 */
class DatabaseTemplateMail implements TemplateMail
{
    public function __construct(private TemplateCatalog $catalog, private TemplateRenderer $renderer, private EmailDelivery $delivery) {}

    public function send(string $template, string $to, array $variables, ?string $providerId = null): void
    {
        $row = $this->catalog->ensure($template); // throws for a key that has neither a row nor a default
        $log = $this->log($row, $to, $variables, $providerId, $row->enabled ? EmailLog::QUEUED : EmailLog::SKIPPED);

        if ($row->enabled) {
            $this->delivery->queue($log);
        }
    }

    /** Admin test-send: delivers right away, even if the template is disabled, and returns the outcome. */
    public function sendNow(EmailTemplate $template, string $to): EmailLog
    {
        $log = $this->log($template, $to, $this->catalog->samples($template->key), null, EmailLog::QUEUED);

        return $this->delivery->deliver($log);
    }

    /** @param  array<string, mixed>  $variables */
    private function log(EmailTemplate $template, string $to, array $variables, ?string $providerId, string $status): EmailLog
    {
        $rendered = $this->renderer->render($template, $variables);

        return EmailLog::create([
            'email_template_id' => $template->id,
            'template_key' => $template->key,
            'provider_id' => $providerId,
            'to_email' => $to,
            'subject' => $rendered['subject'],
            'html_body' => $rendered['html'],
            'text_body' => $rendered['text'],
            'status' => $status,
            'attempts' => 0,
        ]);
    }
}
