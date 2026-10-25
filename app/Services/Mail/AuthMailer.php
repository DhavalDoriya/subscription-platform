<?php

namespace App\Services\Mail;

use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\URL;

/** Account emails (verify, reset, password changed, welcome) sent through the admin-editable templates; links point at the React app. */
class AuthMailer
{
    public function __construct(private TemplateMail $mail, private SettingsService $settings) {}

    public function verifyEmail(User $user): void
    {
        $hash = sha1($user->getEmailForVerification());
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->getKey(), 'hash' => $hash]);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        $this->send('auth.verify_email', $user, [
            'verify_url' => $this->frontendUrl('verify-email', [
                'id' => $user->getKey(), 'hash' => $hash, 'expires' => $query['expires'], 'signature' => $query['signature'],
            ]),
            'expires_in' => '60 minutes',
        ]);
    }

    public function passwordReset(User $user, string $token): void
    {
        $this->send('auth.password_reset', $user, [
            'reset_url' => $this->frontendUrl('reset-password', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]),
            'expires_in' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutes',
        ]);
    }

    public function passwordChanged(User $user): void
    {
        $this->send('auth.password_changed', $user);
    }

    public function welcome(User $user): void
    {
        $this->send('auth.welcome', $user, ['login_url' => $this->frontendUrl('login', [])]);
    }

    /** @param  array<string, mixed>  $variables */
    private function send(string $template, User $user, array $variables = []): void
    {
        $this->mail->send($template, $user->email, $variables + [
            'user.name' => $user->name,
            'user.email' => $user->email,
            'platform.name' => $this->settings->get('general.platform_name'),
        ]);
    }

    /** @param  array<string, string>  $query */
    private function frontendUrl(string $path, array $query): string
    {
        return rtrim(config('app.frontend_url'), '/').'/'.$path.($query ? '?'.http_build_query($query) : '');
    }
}
