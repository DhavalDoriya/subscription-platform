<?php

namespace App\Console\Commands;

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Services\Mail\TemplateMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('providers:remind-stripe-onboarding')]
#[Description('Email approved providers who have not finished Stripe onboarding three days after approval (FR-E1)')]
class RemindStripeOnboarding extends Command
{
    private const AFTER_DAYS = 3;

    public function handle(TemplateMail $mail): int
    {
        $sent = 0;

        $providers = Provider::query()->whereIn('status', [ProviderStatus::Approved, ProviderStatus::Active])
            ->where('stripe_status', '!=', 'enabled')->whereNull('stripe_reminder_sent_at')
            ->where('approved_at', '<=', now()->subDays(self::AFTER_DAYS))->get();

        foreach ($providers as $provider) {
            $owner = $provider->owner();
            if (! $owner) {
                continue;
            }

            $provider->forceFill(['stripe_reminder_sent_at' => now()])->save();

            try {
                $mail->send('provider.stripe_onboarding_reminder', $owner->email, [
                    'user.name' => $owner->name,
                    'provider.name' => $provider->name,
                    'onboarding_url' => rtrim((string) config('app.frontend_url'), '/').'/dashboard/stripe',
                ], $provider->id);
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Sent {$sent} onboarding reminders.");

        return self::SUCCESS;
    }
}
