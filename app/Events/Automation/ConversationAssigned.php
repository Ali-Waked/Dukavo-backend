<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ConversationAssigned implements AutomationEvent
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

    public static function fromConversation(Conversation $conversation, User $assignee, ?int $previousAssigneeId): self
    {
        $conversation->loadMissing('customer');

        return new self(
            tenantId: (int) $conversation->tenant_id,
            storeId: $conversation->store_id !== null ? (int) $conversation->store_id : null,
            context: [
                'conversation_id' => (int) $conversation->getKey(),
                'customer_id' => $conversation->customer_id !== null ? (int) $conversation->customer_id : null,
                'customer_name' => (string) ($conversation->customer?->name ?: $conversation->display_name ?: ''),
                'channel' => (string) $conversation->channel,
                'status' => $conversation->status->value,
                'assigned_to' => (int) $assignee->getKey(),
                'assigned_name' => (string) $assignee->name,
                'previous_assignee_id' => $previousAssigneeId,
                'store_id' => $conversation->store_id !== null ? (int) $conversation->store_id : null,
            ],
            eventKey: 'conversation.assigned:'.$conversation->getKey().':'.$assignee->getKey(),
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
        return AutomationTrigger::ConversationAssigned;
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
