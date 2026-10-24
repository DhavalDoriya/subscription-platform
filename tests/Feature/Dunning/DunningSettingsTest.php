<?php

use App\Services\Dunning\DunningSettings;
use App\Services\Settings\SettingsService;

it('FR-R2 defaults to retries at +1, +3, +5 and +7 days, 7 days of grace and cancel', function () {
    $dunning = app(DunningSettings::class);

    expect($dunning->retryDays())->toBe([1, 3, 5, 7])
        ->and($dunning->graceDays())->toBe(7)
        ->and($dunning->finalAction())->toBe('cancel');
});

it('FR-R2 reads the schedule as sorted whole days and ignores junk', function () {
    app(SettingsService::class)->set('dunning.retry_days', ['5', '1', 'x', '0', '3', '3']);

    expect(app(DunningSettings::class)->retryDays())->toBe([1, 3, 5]);
});

it('FR-R2 an admin edits the schedule, grace period and final action', function () {
    adminSession();

    $this->putJson('/api/v1/admin/settings', ['settings' => ['dunning' => ['retry_days' => ['2', '4'], 'grace_days' => 0, 'final_action' => 'pause']]])->assertOk();

    $dunning = app(DunningSettings::class);
    expect($dunning->retryDays())->toBe([2, 4])->and($dunning->graceDays())->toBe(0)->and($dunning->finalAction())->toBe('pause');
});

it('FR-R2 rejects a schedule that is not whole days, too long or an unknown final action', function () {
    adminSession();

    $this->putJson('/api/v1/admin/settings', ['settings' => ['dunning' => ['retry_days' => ['soon']]]])->assertUnprocessable()->assertJsonValidationErrors('settings.dunning.retry_days.0');
    $this->putJson('/api/v1/admin/settings', ['settings' => ['dunning' => ['retry_days' => ['0']]]])->assertUnprocessable();
    $this->putJson('/api/v1/admin/settings', ['settings' => ['dunning' => ['retry_days' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11']]]])->assertUnprocessable();
    $this->putJson('/api/v1/admin/settings', ['settings' => ['dunning' => ['final_action' => 'delete']]])->assertUnprocessable();
    $this->putJson('/api/v1/admin/settings', ['settings' => ['dunning' => ['grace_days' => 365]]])->assertUnprocessable();
});
