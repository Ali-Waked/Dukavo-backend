<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RoleScope;

/**
 * The single source of truth for Dukavo's permission catalogue.
 *
 * Permissions are seeded from here, granted to roles from here, and the Vue
 * application builds its navigation and button visibility from the same list
 * (`GET /api/v1/auth/me` returns the effective permissions), so the frontend
 * can never drift away from what the backend enforces.
 */
final class PermissionRegistry
{
    /**
     * module => [ action => description ]
     *
     * @var array<string, array<string, string>>
     */
    private const CATALOGUE = [
        'products' => [
            'view' => 'Browse the product catalogue',
            'create' => 'Create products and variants',
            'update' => 'Edit products and variants',
            'delete' => 'Archive and delete products',
        ],
        'categories' => [
            'view' => 'Browse categories',
            'manage' => 'Create, edit and reorder categories',
        ],
        'inventory' => [
            'view' => 'View stock levels and movements',
            'manage' => 'Adjust stock and move inventory',
        ],
        'orders' => [
            'view' => 'Browse orders',
            'create' => 'Create orders',
            'update' => 'Update orders and change their status',
            'delete' => 'Delete orders',
        ],
        'customers' => [
            'view' => 'Browse customers',
            'create' => 'Create customers',
            'update' => 'Edit customers and addresses',
            'delete' => 'Delete customers',
        ],
        'conversations' => [
            'view' => 'Read conversations',
            'reply' => 'Send replies to customers',
            'assign' => 'Assign conversations to staff',
        ],
        'whatsapp' => [
            'view' => 'View WhatsApp accounts',
            'manage' => 'Connect and disconnect WhatsApp accounts',
        ],
        'stores' => [
            'view' => 'Browse stores',
            'manage' => 'Create and update stores',
        ],
        'settings' => [
            'view' => 'View workspace settings',
            'manage' => 'Update workspace settings',
        ],
        'users' => [
            'view' => 'Browse tenant users',
            'manage' => 'Invite, update and remove tenant users',
        ],
        'roles' => [
            'view' => 'Browse roles and permissions',
            'manage' => 'Create roles and change their permissions',
        ],
        'dashboard' => [
            'view' => 'View the dashboard and its analytics',
        ],
        'audit' => [
            'view' => 'Review the audit log',
        ],
    ];

    /**
     * @return list<array{slug: string, name: string, module: string, description: string}>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::CATALOGUE as $module => $actions) {
            foreach ($actions as $action => $description) {
                $slug = "{$module}.{$action}";

                $permissions[] = [
                    'slug' => $slug,
                    'name' => self::humanise($slug),
                    'module' => $module,
                    'description' => $description,
                ];
            }
        }

        return $permissions;
    }

    /**
     * Permission slugs grouped by module, which is what the role editor needs.
     *
     * @return array<string, list<string>>
     */
    public static function groupedByModule(): array
    {
        // Built by hand rather than with array_map over two arrays: that form
        // reindexes the result and throws the module keys away, leaving the
        // frontend with a flat list it cannot group.
        $grouped = [];

        foreach (self::CATALOGUE as $module => $actions) {
            $grouped[$module] = array_map(
                static fn (string $action): string => "{$module}.{$action}",
                array_keys($actions),
            );
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    public static function exists(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    /**
     * @return list<string>
     */
    public static function forModule(string $module): array
    {
        return self::groupedByModule()[$module] ?? [];
    }

    /**
     * The permissions a role starts with when it is created.
     *
     * Platform roles govern Dukavo itself, so they receive tenant-management
     * capabilities rather than the merchant catalogue.
     *
     * @return list<string>
     */
    public static function defaultsFor(RoleScope $scope, string $slug): array
    {
        if ($scope === RoleScope::Platform) {
            return $slug === 'super-admin'
                ? self::slugs()
                : ['users.view', 'users.manage', 'roles.view', 'roles.manage', 'stores.view', 'stores.manage', 'audit.view'];
        }

        return match ($slug) {
            'merchant' => self::slugs(),
            // Staff are deliberately read-mostly: they can work orders and talk
            // to customers but must not reshape the catalogue or the org chart.
            'staff' => [
                'dashboard.view',
                'products.view',
                'categories.view',
                'inventory.view',
                'inventory.manage',
                'orders.view',
                'orders.create',
                'orders.update',
                'customers.view',
                'customers.create',
                'customers.update',
                'conversations.view',
                'conversations.reply',
                'whatsapp.view',
                'stores.view',
            ],
            default => ['dashboard.view'],
        };
    }

    private static function humanise(string $slug): string
    {
        return ucwords(str_replace('.', ' ', $slug));
    }
}
