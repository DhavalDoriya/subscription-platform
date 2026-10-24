<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/** Audit rows are immutable, so no soft deletes here. */
class Activity extends SpatieActivity
{
    use HasUuids;
}
