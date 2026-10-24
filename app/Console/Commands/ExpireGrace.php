<?php

namespace App\Console\Commands;

use App\Services\Dunning\DunningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:expire-grace')]
#[Description('Cancel or pause subscriptions whose grace period has ended (FR-R5)')]
class ExpireGrace extends Command
{
    public function handle(DunningService $dunning): int
    {
        $this->info("Ended {$dunning->expireGrace()} subscriptions after grace.");

        return self::SUCCESS;
    }
}
