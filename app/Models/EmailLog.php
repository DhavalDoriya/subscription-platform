<?php

namespace App\Models;

use Database\Factories\EmailLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One email the platform tried to send (FR-E6): the rendered content as sent, so a failed one can be resent unchanged.
 * Platform-wide (admin only); `provider_id` just says which brand it was sent on behalf of, so it has no tenant scope.
 */
#[Fillable(['email_template_id', 'template_key', 'provider_id', 'to_email', 'subject', 'html_body', 'text_body', 'status', 'error', 'attempts', 'sent_at'])]
class EmailLog extends Model
{
    /** @use HasFactory<EmailLogFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'sent_at' => 'datetime'];
    }
}
