<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Configuration\ConfigurationApplier;
use App\Services\Configuration\ConfigurationRegistry;
use App\Services\Configuration\ConfigurationResolver;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Boots the platform configuration layer.
 *
 * The registry and resolver are singletons because the registry builds a few
 * hundred definitions from configuration and the resolver caches database rows
 * for the life of the process. Applying values during boot means a request sees
 * an operator's override without every service having to ask for it, and the
 * queue hook refresh means a long-lived worker does not keep serving the values
 * it loaded when it started.
 *
 * Applying configuration is best-effort: a fresh clone whose database is not
 * migrated yet must still be able to boot and run `migrate`. The resolver
 * already falls back to environment and defaults in that situation; this guard
 * only protects against a configuration file that an override cannot address.
 */
final class PlatformConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConfigurationRegistry::class);
        $this->app->singleton(ConfigurationResolver::class);
        $this->app->singleton(ConfigurationApplier::class);
    }

    public function boot(): void
    {
        $this->applyQuietly();

        Queue::before(function (JobProcessing $event): void {
            // A worker resolves configuration once at boot; refreshing before
            // each job makes a dashboard change visible to queued work without
            // a deploy. `applyQuietly` keeps a broken override from failing the
            // job that happened to run next.
            $this->applyQuietly();
        });
    }

    private function applyQuietly(): void
    {
        try {
            $this->app->make(ConfigurationApplier::class)->apply();
        } catch (Throwable $exception) {
            // Never let configuration bootstrap prevent the application from
            // starting. The environment and definition defaults are complete
            // on their own, and the failure is visible in the log.
            report($exception);
        }
    }
}
