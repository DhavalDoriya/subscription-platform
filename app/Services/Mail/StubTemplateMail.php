<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Log;

/** Test double bound in tests/Pest.php: remembers every email in `$sent` and delivers nothing. Production uses DatabaseTemplateMail. */
class StubTemplateMail implements TemplateMail
{
    /** @var array<int, array{template: string, to: string, variables: array<string, mixed>, provider_id: ?string}> */
    public array $sent = [];

    public function send(string $template, string $to, array $variables, ?string $providerId = null): void
    {
        $this->sent[] = ['template' => $template, 'to' => $to, 'variables' => $variables, 'provider_id' => $providerId];

        Log::info("Mail stub: {$template} to {$to}", ['provider_id' => $providerId]);
    }
}
