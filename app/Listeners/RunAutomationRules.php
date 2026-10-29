<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Automation\AutomationEvent;
use App\Models\Tenant;
use App\Services\Automation\AutomationEngine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resolves the event's tenant before any rule is evaluated.
 *
 * Queue workers have no request-derived tenant context, so restoring it here is
 * what makes the global tenant scope safe inside automation execution.
 */
final class RunAutomationRules implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        private readonly AutomationEngine $engine,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(AutomationEvent $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId());

        if (! $tenant instanceof Tenant) {
            return;
        }

        $this->tenants->run($tenant, fn () => $this->engine->handle($event));
    }
}
