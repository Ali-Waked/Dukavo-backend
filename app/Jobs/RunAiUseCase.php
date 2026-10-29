<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Ai\Contracts\QueueableUseCase;
use App\Services\Ai\Features\FeatureRegistry;
use App\Services\Ai\Support\IdempotencyStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RunAiUseCase implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $useCaseKey,
        private readonly array $payload,
        private readonly ?int $tenantId = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function handle(
        FeatureRegistry $registry,
        TenantContext $tenants,
        IdempotencyStore $idempotency,
    ): void {
        if ($this->idempotencyKey !== null && $idempotency->has($this->idempotencyKey)) {
            return;
        }

        $feature = $registry->get($this->useCaseKey);

        if ($feature === null) {
            return;
        }

        $instance = app($feature->service);

        if (! $instance instanceof QueueableUseCase) {
            return;
        }

        $run = fn (): array => $instance->handleQueued($this->payload);

        // The tenant is restored from the job's own payload, never from ambient
        // state: a worker handles many tenants in one process, and the scope
        // must be right before any tenant-owned model is queried.
        $tenant = $this->tenantId !== null ? Tenant::query()->find($this->tenantId) : null;

        $result = $tenant instanceof Tenant ? $tenants->run($tenant, $run) : $run();

        if ($this->idempotencyKey !== null) {
            $idempotency->store($this->idempotencyKey, $result);
        }
    }
}
