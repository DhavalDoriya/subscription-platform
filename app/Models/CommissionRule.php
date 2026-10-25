<?php

namespace App\Models;

use Database\Factories\CommissionRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A platform fee rate from a date on (FR-CM1, FR-CM2). `provider_id` null is the global default. History is kept: a
 * change adds a rule with a later `effective_from`, it never edits an old one. Not tenant-scoped: admins manage it.
 */
#[Fillable(['provider_id', 'percent', 'effective_from', 'created_by'])]
class CommissionRule extends Model
{
    /** @use HasFactory<CommissionRuleFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['percent' => 'decimal:2', 'effective_from' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
