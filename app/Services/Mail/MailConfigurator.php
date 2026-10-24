<?php

namespace App\Services\Mail;

use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Turns the admin mail settings (FR-E5, secrets encrypted in the settings store) into a mailer at send time, so a change
 * applies to the very next email. Driver `default` keeps whatever the server's .env says (Mailpit locally).
 */
class MailConfigurator
{
    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed>|null null = use the server's default mailer */
    public function transportConfig(): ?array
    {
        $driver = $this->settings->get('mail.driver') ?? 'default';

        return match ($driver) {
            'smtp' => [
                'transport' => 'smtp',
                'host' => $this->settings->get('mail.host'),
                'port' => $this->settings->get('mail.port'),
                'username' => $this->settings->get('mail.username'),
                'password' => $this->settings->get('mail.password'),
                'scheme' => $this->settings->get('mail.encryption') === 'ssl' ? 'smtps' : 'smtp',
                'timeout' => 10,
            ],
            // Needs symfony/mailgun-mailer: `username` is unused, `api_domain` is the sending domain.
            'mailgun' => ['transport' => 'mailgun', 'domain' => $this->settings->get('mail.api_domain'), 'secret' => $this->settings->get('mail.api_key')],
            // Needs aws/aws-sdk-php: `username` is the access key id, `api_key` the secret, `api_domain` the region.
            'ses' => [
                'transport' => 'ses', 'key' => $this->settings->get('mail.username'), 'secret' => $this->settings->get('mail.api_key'),
                'region' => $this->settings->get('mail.api_domain') ?: 'us-east-1',
            ],
            default => null,
        };
    }

    /** @return array{address: ?string, name: ?string} */
    public function from(): array
    {
        return [
            'address' => $this->settings->get('mail.from_address') ?: config('mail.from.address'),
            'name' => $this->settings->get('mail.from_name') ?: config('mail.from.name'),
        ];
    }

    public function mailer(): Mailer
    {
        $config = $this->transportConfig();

        return $config === null ? Mail::mailer() : Mail::build($config);
    }
}
