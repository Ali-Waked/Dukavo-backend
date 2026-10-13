<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Rbac\PermissionService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Builds the demo platform end to end.
 *
 * The whole run is wrapped by {@see WithoutModelEvents} (via __invoke), so
 * model hooks such as tenant scoping and audit recording stay quiet: every
 * seeder writes its columns — including `tenant_id` and audit rows — itself,
 * with backdated timestamps the services would never produce.
 *
 * Seeding is deterministic: the RNG is seeded once below and `fake()` is
 * never called, so two fresh runs produce byte-identical data.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        mt_srand(20261007);

        // Seeders eager load and write explicit column lists, but a stray
        // lazy load should degrade to a logged violation rather than abort a
        // long run halfway through.
        Model::handleLazyLoadingViolationUsing(static fn (): null => null);

        $this->call([
            RolePermissionSeeder::class,
            PlatformUserSeeder::class,
            TenantSeeder::class,
            CatalogSeeder::class,
            CustomerSeeder::class,
            OrderSeeder::class,
            InventorySeeder::class,
            ConversationSeeder::class,
            AuditLogSeeder::class,
            MarketingContentSeeder::class,
            ContentPageSeeder::class,
        ]);

        // Permission answers come from a cached role catalogue; drop it so the
        // first request after seeding reads the rows just written.
        app(PermissionService::class)->flushRoleCatalogue();

        $this->command?->info('Demo database seeded.');
    }
}
