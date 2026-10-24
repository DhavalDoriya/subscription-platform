<?php

namespace App\Console\Commands;

use App\Services\Billing\Reconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:reconcile')]
#[Description('Compare local subscriptions with Stripe and repair any drift (runs nightly)')]
class ReconcileBilling extends Command
{
    public function handle(Reconciler $reconciler): int
    {
        $report = $reconciler->run();

        $this->info("Checked {$report['checked']} subscriptions across {$report['providers']} providers: {$report['drifted']} drifted, {$report['errors']} errors.");

        return $report['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
