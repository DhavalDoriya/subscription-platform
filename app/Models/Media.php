<?php

namespace App\Models;

use App\Models\Concerns\TenantOwned;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['provider_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'width', 'height', 'alt'])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasUuids, SoftDeletes, TenantOwned;

    protected $table = 'media';

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    /** Public URL of the stored file; storefront image fields take this like any other https URL. */
    protected function url(): Attribute
    {
        return Attribute::get(function () {
            $url = Storage::disk($this->disk)->url($this->path);

            return str_starts_with($url, '/') ? url($url) : $url;
        });
    }
}
