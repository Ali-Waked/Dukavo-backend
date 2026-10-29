<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;
use App\Models\RecoveryOpportunity;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class RecoveryOpportunityDetected implements AutomationEvent
{
    use Dispatchable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        private int $tenantId,
        private ?int $storeId,
        private array $context,
        private string $eventKey,
    ) {}

    public static function fromOpportunity(RecoveryOpportunity $opportunity): self
    {
        $opportunity->loadMissing('customer');

        return new self(
            tenantId: (int) $opportunity->tenant_id,
            storeId: $opportunity->store_id !== null ? (int) $opportunity->store_id : null,
            context: [
                'opportunity_id' => (int) $opportunity->getKey(),
                'reason' => $opportunity->reason->value,
                'status' => $opportunity->status->value,
                'estimated_value' => (float) ($opportunity->estimated_value ?? 0),
                'currency' => (string) ($opportunity->currency ?? ''),
                'store_id' => $opportunity->store_id !== null ? (int) $opportunity->store_id : null,
                'customer_id' => $opportunity->customer_id !== null ? (int) $opportunity->customer_id : null,
                'customer_name' => (string) ($opportunity->customer?->name ?? ''),
                'conversation_id' => $opportunity->conversation_id !== null ? (int) $opportunity->conversation_id : null,
                'cart_id' => $opportunity->cart_id !== null ? (int) $opportunity->cart_id : null,
                'assigned_to' => $opportunity->assigned_to !== null ? (int) $opportunity->assigned_to : null,
            ],
            eventKey: 'recovery.opportunity_detected:'.$opportunity->getKey(),
        );
    }

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
        return AutomationTrigger::RecoveryOpportunityDetected;
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
