<?php

namespace App\Console\Commands;

use App\Jobs\RetryInvoicePayment;
use App\Models\Invoice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:retry-payments')]
#[Description('Queue a retry for every failed invoice whose next retry is due (FR-R7)')]
class RetryPayments extends Command
{
    public function handle(): int
    {
        $count = 0;

        Invoice::where('dunning_status', 'retrying')->where('next_retry_at', '<=', now())->orderBy('next_retry_at')
            ->each(function (Invoice $invoice) use (&$count) {
                RetryInvoicePayment::dispatch($invoice->id);
                $count++;
            });

        $this->info("Queued {$count} payment retries.");

        return self::SUCCESS;
    }
}
