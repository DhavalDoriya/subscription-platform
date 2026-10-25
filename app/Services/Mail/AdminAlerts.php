<?php

namespace App\Services\Mail;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Emails every admin through an `admin.*` template (FR-E1). A mail failure never breaks the caller. */
class AdminAlerts
{
    public function __construct(private TemplateMail $mail) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  string|null  $dedupeKey  when given, the same alert is sent at most once per `$minutes`
     */
    public function send(string $template, array $variables, ?string $dedupeKey = null, int $minutes = 60): bool
    {
        if ($dedupeKey !== null && ! Cache::add("alert:{$template}:{$dedupeKey}", true, now()->addMinutes($minutes))) {
            return false;
        }

        $variables += ['admin_url' => rtrim((string) config('app.frontend_url'), '/').'/admin'];

        foreach (User::role(['admin', 'super_admin'], 'api')->get() as $admin) {
            try {
                $this->mail->send($template, $admin->email, $variables + ['user.name' => $admin->name]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return true;
    }
}
