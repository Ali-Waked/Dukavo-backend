<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single capability, named `module.action` (e.g. `orders.update`).
 *
 * Permissions are global records — the same row is shared by every tenant —
 * because they describe what Dukavo can do, not what a given tenant owns.
 */
#[Fillable(['name', 'slug', 'module', 'description'])]
class Permission extends Model
{
    protected function casts(): array
    {
        return [
            //
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    #[Scope]
    protected function forModule(Builder $query, string $module): void
    {
        $query->where('module', $module);
    }
}
