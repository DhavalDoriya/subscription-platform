<?php

namespace App\Models;

use App\Enums\ProviderStatus;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'status', 'business_category', 'country', 'support_email', 'currency', 'timezone', 'rejection_reason', 'approved_at', 'settings'])]
class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ProviderStatus::class,
            'approved_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProviderUser::class);
    }

    public function owner(): ?User
    {
        $members = $this->relationLoaded('members') ? $this->members : $this->members()->with('user')->get();

        return $members->firstWhere('role', 'owner')?->user;
    }

    public function customers(): HasMany
    {
        return $this->hasMany(ProviderCustomer::class);
    }
}
