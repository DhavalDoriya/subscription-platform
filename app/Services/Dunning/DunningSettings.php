<?php

namespace App\Services\Dunning;

use App\Services\Settings\SettingsService;

/** The admin-editable dunning policy (FR-R2, FR-R5) read as typed values. */
class DunningSettings
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Days after the first failure at which a retry runs, ascending.
     *
     * @return array<int, int>
     */
    public function retryDays(): array
    {
        $days = array_filter(array_map('intval', (array) $this->settings->get('dunning.retry_days')), fn (int $day) => $day > 0);

        return array_values(array_unique(collect($days)->sort()->all()));
    }

    public function graceDays(): int
    {
        return (int) $this->settings->get('dunning.grace_days');
    }

    /** What happens when grace ends without payment: `cancel` or `pause`. */
    public function finalAction(): string
    {
        return $this->settings->get('dunning.final_action') === 'pause' ? 'pause' : 'cancel';
    }
}
