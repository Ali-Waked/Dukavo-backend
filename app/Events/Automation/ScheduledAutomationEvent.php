<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;

/**
 * A synthetic event produced by the automation scheduler.
 *
 * Scheduled rules do not have a database row of their own to react to, so the
 * scheduler supplies a dated context and a dedupe key. Using a real event class
 * keeps the engine's single execution path unchanged.
 */
final readonly class ScheduledAutomationEvent implements AutomationEvent
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        private int $tenantId,
        private ?int $storeId,
        private AutomationTrigger $trigger,
        private string $eventKey,
        private array $context,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function storeId(): ?int
    {
        return $this->storeId;
    }

    public function trigger(): AutomationTrigger
    {
        return $this->trigger;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function dedupeKey(): string
    {
        return $this->eventKey;
    }
}
