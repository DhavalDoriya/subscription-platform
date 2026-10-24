<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\PageBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['provider_id', 'storefront_page_id', 'type', 'data', 'position', 'visible'])]
class PageBlock extends Model
{
    /** @use HasFactory<PageBlockFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['data' => 'array', 'visible' => 'boolean', 'position' => 'integer'];
    }
}
