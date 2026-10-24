<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['key', 'value', 'is_encrypted'])]
class Setting extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }
}
