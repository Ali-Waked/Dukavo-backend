<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Models\Concerns\Auditable;
use App\Models\Scopes\TenantScope;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A merchant account on the Dukavo platform.
 *
 * `tenants` is the root of the isolation hierarchy: everything below it
 * (stores, catalogue, inventory, customers, orders, conversations) is
 * tenant-owned and protected by {@see TenantScope}.
 */
#[Fillable(['name', 'slug', 'status', 'contact_email', 'phone', 'currency', 'timezone', 'locale', 'settings'])]
#[Hidden(['settings'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use Auditable, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'slug', 'status', 'contact_email', 'currency', 'timezone'];
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['is_owner'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', TenantStatus::Active);
    }
}
