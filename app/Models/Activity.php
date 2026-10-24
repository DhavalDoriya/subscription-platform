<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use LogicException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/** Audit rows are immutable (FR-L1): no soft deletes, and any update or delete is refused. */
class Activity extends SpatieActivity
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('The audit log is append-only.'));
    }
}
