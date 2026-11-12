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
        'recovery' => [
            'view' => 'Browse lost-sale opportunities and recovered revenue',
            'manage' => 'Assign, snooze, dismiss and contact recovery opportunities',
        ],
        'automation' => [
            'view' => 'Browse automation rules, run history and internal tasks',
            'manage' => 'Create, edit, pause and delete automation rules',
        ],
        'whatsapp' => [
            'view' => 'View WhatsApp accounts',
            'manage' => 'Connect and disconnect WhatsApp accounts',
        ],
        'integrations' => [
            'view' => 'View channel integrations, their health and customer identities',
            'manage' => 'Connect, test, update and disconnect channel integrations',
        ],
        'stores' => [
            'view' => 'Browse stores',
            'manage' => 'Create and update stores',
        ],
        'storefront' => [
            'view' => 'View storefront settings and preview the public shop',
            'manage' => 'Publish and configure the public storefront',
        ],
        'shipping' => [
            'view' => 'Browse delivery zones and fees',
            'manage' => 'Create and edit delivery zones and fees',
        ],
        'payments' => [
            'view' => 'View payment methods, provider connections and transactions',
            'manage' => 'Connect providers and configure payment methods and instructions',
            'refund' => 'Issue refunds against captured online payments',
        ],
        'settings' => [
            'view' => 'View workspace settings',
            'manage' => 'Update workspace settings',
        ],
        'ai' => [
            'use' => 'Use AI assistance and propose actions',
            'approve' => 'Approve, reject and execute AI-proposed actions',
        ],
        'ai_settings' => [
            'view' => 'View AI settings, usage and preferences for the workspace',
            'manage' => 'Change AI features and preferences for the workspace',
        ],
        'ai_admin' => [
            'view' => "View Dukavo's AI providers, routing, usage and prompt administration",
            'manage' => 'Configure providers, credentials, models, task routing and prompts',
            'rotate' => 'Replace provider API keys and test provider connections',
        ],
        'translations' => [
            'view' => 'Browse translations and their review status',
            'generate' => 'Generate AI translations',
            'review' => 'Edit, approve, reject and regenerate translations',
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
        'profit' => [
            'view' => 'View profit, margin and financial intelligence reports',
            'export' => 'Export financial reports',
        ],
        'audit' => [
            'view' => 'Review the audit log',
        ],
        'notifications' => [
            'view' => 'View platform notification campaigns and delivery statistics',
            'manage' => 'Create, schedule, publish and cancel platform notification campaigns',
        ],
        'platform_payments' => [
            'view' => 'View payment providers, countries, methods and webhook evidence',
            'manage' => 'Enable providers, manage country coverage and payment methods',
        ],
        'integrations_admin' => [
            'view' => "View Dukavo's channel providers, tenant connections and integration health",
            'manage' => 'Configure platform channel providers and inspect integration failures',
        ],
        'platform_settings' => [
            'view' => 'View platform configuration, its sources and change history',
            'update' => 'Change non-secret platform configuration',
            'secrets' => 'Replace encrypted platform secrets such as mail, storage and WhatsApp credentials',
            'test' => 'Run integration health probes from Platform Settings',
        ],
        'platform_features' => [
            'view' => 'View platform feature availability',
            'manage' => 'Enable or disable platform feature availability',
        ],
        'platform_security' => [
            'view' => 'View password, session, rate-limit and maintenance policy',
            'manage' => 'Change password, session, rate-limit and maintenance policy',
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
                : ['users.view', 'users.manage', 'roles.view', 'roles.manage', 'stores.view', 'stores.manage', 'audit.view', 'ai.use', 'notifications.view', 'translations.view', 'translations.generate', 'translations.review', 'platform_payments.view', 'platform_payments.manage', 'ai_admin.view', 'ai_admin.manage', 'ai_admin.rotate', 'integrations_admin.view', 'integrations_admin.manage', 'platform_settings.view', 'platform_settings.update', 'platform_settings.test', 'platform_features.view', 'platform_features.manage', 'platform_security.view', 'platform_security.manage'];
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
                'recovery.view',
                'recovery.manage',
                'automation.view',
                'whatsapp.view',
                'integrations.view',
                'stores.view',
                'storefront.view',
                'shipping.view',
                'payments.view',
                'ai.use',
                'ai.approve',
                'translations.view',
            ],
            default => ['dashboard.view'],
        };
    }

    private static function humanise(string $slug): string
    {
        return ucwords(str_replace('.', ' ', $slug));
    }
}
