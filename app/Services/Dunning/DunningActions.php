<?php

namespace App\Services\Dunning;

use App\Exceptions\ApiException;
use App\Models\Invoice;

/** The manual dunning actions of providers and admins (FR-R9), with the business-rule errors the API reports. */
class DunningActions
{
    public function __construct(private DunningService $dunning) {}

    /** Charge the invoice now, whatever the schedule says. A declined card is a normal outcome and comes back as the invoice's new state. */
    public function retry(Invoice $invoice): Invoice
    {
        if (! $this->dunning->canRetry($invoice)) {
            throw new ApiException(__('api.dunning.not_retryable'), 409, 'not_retryable');
        }

        $this->dunning->retry($invoice, manual: true);

        return $invoice->refresh();
    }

    public function resolve(Invoice $invoice): Invoice
    {
        if (! in_array($invoice->dunning_status, DunningService::ACTIVE, true)) {
            throw new ApiException(__('api.dunning.not_resolvable'), 409, 'not_resolvable');
        }

        $this->dunning->resolve($invoice);

        return $invoice->refresh();
    }
}
