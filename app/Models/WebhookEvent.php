<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Every Stripe event we receive, stored before it is processed so replays and redeliveries are safe (FR-PM4). */
#[Fillable(['stripe_event_id', 'type', 'account_id', 'livemode', 'payload', 'status', 'attempts', 'processed_at', 'error'])]
class WebhookEvent extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['payload' => 'array', 'livemode' => 'boolean', 'processed_at' => 'datetime'];
    }
}
