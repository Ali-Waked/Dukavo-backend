<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;

/**
 * A business event that automation rules may react to.
 *
 * Implementations carry only the tenant, optional store, a stable dedupe key
 * and a flat context of scalar values. That keeps a queued event serializable
 * and stops a rule from reaching into arbitrary Eloquent models when it runs.
 */
interface AutomationEvent
{
    public function tenantId(): int;

    public function storeId(): ?int;

    public function trigger(): AutomationTrigger;

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array;

    public function dedupeKey(): string;
}
