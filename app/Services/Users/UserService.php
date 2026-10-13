<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating and maintaining the people who use Dukavo.
 *
 * A user is a global identity; what they may do is decided by role assignments
 * per tenant. This service therefore always pairs a user record change with the
 * tenant membership and role change that makes it meaningful.
 */
class UserService
{
    public function __construct(private readonly TenantService $tenants) {}

    /**
     * Create a user inside a tenant with a tenant scoped role.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createForTenant(Tenant $tenant, Role $role, array $attributes): User
    {
        return DB::transaction(function () use ($tenant, $role, $attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $this->normaliseEmail($attributes['email']),
                'phone' => $attributes['phone'] ?? null,
                // A temporary password is generated when none is supplied; the
                // user is expected to reset it. Hashing is the model cast's job.
                'password' => $attributes['password'] ?? Str::password(24),
                'status' => $attributes['status'] ?? UserStatus::Invited,
                'locale' => $attributes['locale'] ?? $tenant->locale,
            ]);

            $this->tenants->addUser($tenant, $user, $role, (bool) ($attributes['is_owner'] ?? false));

            return $user;
        });
    }

    /**
     * Create a fully independent platform identity (no tenant membership).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPlatformUser(Role $role, array $attributes): User
    {
        return DB::transaction(function () use ($role, $attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $this->normaliseEmail($attributes['email']),
                'phone' => $attributes['phone'] ?? null,
                'password' => $attributes['password'] ?? Str::password(24),
                'status' => $attributes['status'] ?? UserStatus::Active,
                'locale' => $attributes['locale'] ?? 'en',
            ]);

            $user->roleAssignments()->create([
                'role_id' => $role->getKey(),
                'tenant_id' => null,
            ]);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateUser(User $user, array $attributes): User
    {
        if (isset($attributes['email'])) {
            $attributes['email'] = $this->normaliseEmail($attributes['email']);
        }

        $user->fill($attributes)->save();

        return $user;
    }

    public function changeStatus(User $user, UserStatus $status): User
    {
        $user->forceFill(['status' => $status])->save();

        return $user;
    }

    /**
     * Suspending a user immediately revokes their sessions so the change takes
     * effect without waiting for a token to expire.
     */
    public function suspend(User $user): User
    {
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        $user->tokens()->delete();

        return $user;
    }

    public function delete(User $user): void
    {
        // Audit trails and order history must survive the user, so the record is
        // deactivated instead of deleted. Only the platform may remove a user
        // outright, and even then orders keep their snapshots.
        $this->suspend($user);
    }

    private function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
