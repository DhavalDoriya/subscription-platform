<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The content of an email template as of one saved version (FR-E1). */
#[Fillable(['email_template_id', 'version', 'subject', 'html_body', 'text_body', 'created_by'])]
class EmailTemplateVersion extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
