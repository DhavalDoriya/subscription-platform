<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A user's membership of a provider (owner now, staff roles in v1.1). Not tenant-scoped: it is how a user finds their tenant. */
#[Fillable(['provider_id', 'user_id', 'role'])]
class ProviderUser extends Model
{
    use HasUuids, SoftDeletes;

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
