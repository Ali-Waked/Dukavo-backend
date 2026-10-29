<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ConversationMessageReceived implements AutomationEvent
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

    public static function fromMessage(Conversation $conversation, ConversationMessage $message): self
    {
        $conversation->loadMissing('customer');

        $body = trim((string) $message->body);

        return new self(
            tenantId: (int) $conversation->tenant_id,
            storeId: $conversation->store_id !== null ? (int) $conversation->store_id : null,
            context: [
                'conversation_id' => (int) $conversation->getKey(),
                'message_id' => (int) $message->getKey(),
                'customer_id' => $conversation->customer_id !== null ? (int) $conversation->customer_id : null,
                'customer_name' => (string) ($conversation->customer?->name ?: $conversation->display_name ?: ''),
                'channel' => (string) $conversation->channel,
                'status' => $conversation->status->value,
                'assigned_to' => $conversation->assigned_to !== null ? (int) $conversation->assigned_to : null,
                'preview' => $body === '' ? '' : mb_substr($body, 0, 240),
                'store_id' => $conversation->store_id !== null ? (int) $conversation->store_id : null,
            ],
            eventKey: 'conversation.message_received:'.$message->getKey(),
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
        return AutomationTrigger::ConversationMessageReceived;
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
