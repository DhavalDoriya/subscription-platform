<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Services\Mail\EmailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delivers one queued email from the delivery log (FR-E6). Failures are stored on the log and resent by an admin. */
class SendEmailLog implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $emailLogId) {}

    public function handle(EmailDelivery $delivery): void
    {
        $log = EmailLog::find($this->emailLogId);

        if ($log && $log->status === EmailLog::QUEUED) {
            $delivery->deliver($log);
        }
    }
}
