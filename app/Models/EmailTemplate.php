<?php

namespace App\Models;

use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An admin-editable global email template (FR-E1) identified by a stable key such as `dunning.payment_failed`. */
#[Fillable(['key', 'name', 'description', 'subject', 'html_body', 'text_body', 'enabled', 'version', 'updated_by'])]
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'version' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EmailTemplateVersion::class)->orderByDesc('version');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }
}
