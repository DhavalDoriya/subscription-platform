<?php

namespace Database\Factories;

use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailLog> */
class EmailLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'template_key' => 'dunning.payment_failed',
            'to_email' => fake()->safeEmail(),
            'subject' => 'Payment failed',
            'html_body' => '<p>Payment failed</p>',
            'text_body' => 'Payment failed',
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
        ];
    }
}
