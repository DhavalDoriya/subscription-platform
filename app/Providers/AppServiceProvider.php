<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->when(
            $this->app->isProduction(),
            fn (Password $rule) => $rule->uncompromised(),
        ));

        // Emails link to the React app; the app calls the API with the same query string.
        VerifyEmail::createUrlUsing(function ($notifiable) {
            $signed = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
            );
            parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

            return $this->frontendUrl('verify-email', [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
                'expires' => $query['expires'],
                'signature' => $query['signature'],
            ]);
        });

        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => $this->frontendUrl('reset-password', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]));
    }

    /** @param array<string, string> $query */
    private function frontendUrl(string $path, array $query): string
    {
        return rtrim(config('app.frontend_url'), '/').'/'.$path.'?'.http_build_query($query);
    }
}
