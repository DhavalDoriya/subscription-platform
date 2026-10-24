<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\StorefrontPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A provider's storefront home page: an editable draft (blocks + seo) and the snapshot that is live. */
#[Fillable(['provider_id', 'seo', 'published_snapshot', 'published_at'])]
class StorefrontPage extends Model
{
    /** @use HasFactory<StorefrontPageFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected function casts(): array
    {
        return ['seo' => 'array', 'published_snapshot' => 'array', 'published_at' => 'datetime'];
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->orderBy('position');
    }
}
