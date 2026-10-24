<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Dunning\DunningService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Retries one failed invoice (FR-R7). Unique per invoice, and the service re-checks that it is still due under a lock. */
class RetryInvoicePayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a failed charge is recorded as an attempt; never re-run the job blindly

    public int $uniqueFor = 300;

    public function __construct(public string $invoiceId) {}

    public function uniqueId(): string
    {
        return $this->invoiceId;
    }

    public function handle(DunningService $dunning): void
    {
        if ($invoice = Invoice::find($this->invoiceId)) {
            $dunning->retry($invoice);
        }
    }
}
