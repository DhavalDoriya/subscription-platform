<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/** An email whose subject, html and text were already rendered from a template (nothing left to compile). */
class RenderedMail extends Mailable
{
    public function __construct(string $subject, private string $htmlBody, private string $textBody)
    {
        $this->subject($subject);
    }

    public function build(): static
    {
        return $this->html($this->htmlBody)
            ->withSymfonyMessage(fn (Email $message) => $message->text($this->textBody));
    }
}
