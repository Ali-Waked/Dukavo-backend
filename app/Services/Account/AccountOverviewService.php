<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\SecurityEvent;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\PermissionService;
use Illuminate\Support\Collection;

/**
 * The personal account overview.
 *
 * This is deliberately an identity summary, not a business dashboard: it counts
 * the workspaces the person belongs to and the stores inside them, and never
 * touches orders, sales, revenue or profit. A user's own overview must not leak a
 * merchant's commercial performance, and it must not become a per-page query
 * storm either — every number here comes from one bounded query.
 */
final class AccountOverviewService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ProfileCompletion $completion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $tenants = $user->tenants()->orderBy('tenants.name')->get();
        $tenantIds = $tenants->pluck('id')->all();

        $storesCount = $tenantIds === []
            ? 0
            : Store::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenantIds)->count();

        return [
            'account' => [
                'member_since' => $user->created_at?->toIso8601String(),
                'age_days' => $user->created_at === null ? 0 : (int) $user->created_at->diffInDays(now()),
                'status' => $user->status->value,
                'status_label' => $user->status->label(),
                'type' => $this->accountType($user, $tenants),
                'email_verified' => $user->email_verified_at !== null,
                'phone_verified' => $user->phone_verified_at !== null,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'profile' => $this->completion->for($user),
            'workspaces' => [
                'count' => $tenants->count(),
                'stores_count' => $storesCount,
                'items' => $this->workspaceItems($user, $tenants),
            ],
            'security' => [
                'active_sessions' => $user->tokens()->count(),
                'recent_events' => SecurityEvent::query()
                    ->where('user_id', $user->getKey())
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     * @return list<array{id: int, name: string, slug: string, roles: list<string>, is_owner: bool}>
     */
    private function workspaceItems(User $user, Collection $tenants): array
    {
        return $tenants->map(function ($tenant) use ($user): array {
            return [
                'id' => (int) $tenant->getKey(),
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
                'roles' => $this->permissions->rolesFor($user, $tenant)->pluck('slug')->values()->all(),
                'is_owner' => (bool) ($tenant->pivot->is_owner ?? false),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     */
    private function accountType(User $user, Collection $tenants): string
    {
        if ($this->permissions->isPlatformOperator($user)) {
            return 'platform';
        }

        if ($tenants->isEmpty()) {
            return 'member';
        }

        $isOwner = $tenants->contains(fn ($tenant): bool => (bool) ($tenant->pivot->is_owner ?? false));

        return $isOwner ? 'merchant' : 'staff';
    }
}
