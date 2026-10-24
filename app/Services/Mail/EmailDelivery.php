<?php

namespace App\Services\Mail;

use App\Jobs\SendEmailLog;
use App\Mail\RenderedMail;
use App\Models\EmailLog;
use Throwable;

/** Sends the rendered email of one log row and records the outcome (FR-E6). A failure is stored, never thrown. */
class EmailDelivery
{
    public function __construct(private MailConfigurator $configurator) {}

    public function queue(EmailLog $log): void
    {
        SendEmailLog::dispatch($log->id)->afterCommit();
    }

    public function deliver(EmailLog $log): EmailLog
    {
        $log->attempts++;

        try {
            $this->send($log->to_email, new RenderedMail($log->subject, $log->html_body, $log->text_body));
            $log->fill(['status' => EmailLog::SENT, 'error' => null, 'sent_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            $log->fill(['status' => EmailLog::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        $log->save();

        return $log;
    }

    /** Re-delivers a failed email unchanged, right away. */
    public function resend(EmailLog $log): EmailLog
    {
        return $this->deliver($log);
    }

    /** Sends one message through the configured mailer; throws when the transport refuses it. */
    public function send(string $to, RenderedMail $mail): void
    {
        $from = $this->configurator->from();

        if ($from['address']) {
            $mail->from($from['address'], $from['name']);
        }

        $this->configurator->mailer()->to($to)->send($mail);
    }
}
