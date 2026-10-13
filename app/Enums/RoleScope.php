<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Determines where a role may be assigned.
 *
 * Platform roles are global (Dukavo staff) and carry a NULL tenant_id when
 * attached to a user. Tenant roles only grant capabilities inside the tenant
 * they are assigned for, which is what keeps one merchant from reaching
 * another merchant's data.
 */
enum RoleScope: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform',
            self::Tenant => 'Tenant',
        };
    }

    /**
     * Whether the role assignment must carry a tenant id.
     */
    public function requiresTenant(): bool
    {
        return $this === self::Tenant;
    }
}
