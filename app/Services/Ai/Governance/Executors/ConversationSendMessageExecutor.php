<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Executors;

use App\Enums\AiActionClassification;
use App\Enums\MessageDirection;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Conversations\ConversationService;

/**
 * Sends a customer-visible reply.
 *
 * This is high risk because an outbound message cannot be unsent. The action is
 * approved by a person, and execution is keyed so a retry after the provider
 * accepted the message does not deliver it to the customer twice.
 */
final class ConversationSendMessageExecutor implements AiActionExecutor
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly WhatsAppGateway $gateway,
    ) {}

    public function actionType(): string
    {
        return 'conversation.send_message';
    }

    public function classification(): AiActionClassification
    {
        return AiActionClassification::HighRisk;
    }

    public function requiredPermission(): string
    {
        return 'conversations.reply';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        $conversation = $this->resolveConversation($payload['conversation_id'] ?? null);
        $body = $this->stringOrNull($payload['body'] ?? null, 4000);

        if ($body === null) {
            throw BusinessRuleViolation::because('The proposed message is empty.');
        }

        return [
            'conversation_id' => $conversation->getKey(),
            'channel' => $conversation->channel,
            'customer_name' => $conversation->customer?->name,
            'channel_account' => $conversation->channel_account,
            'body' => $body,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function revalidate(array $payload): array
    {
        $conversation = $this->resolveConversation($payload['conversation_id'] ?? null);

        if ($conversation->channel !== (string) ($payload['channel'] ?? '')) {
            throw BusinessRuleViolation::because('The conversation channel changed after this message was proposed.');
        }

        return $this->resolve([
            'conversation_id' => $conversation->getKey(),
            'body' => $payload['body'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, User $actor, string $idempotencyKey): array
    {
        $conversation = Conversation::query()->findOrFail((int) $payload['conversation_id']);

        // If a previous attempt got the provider's acceptance and then failed
        // before the action row was committed, the message is already here.
        // `idempotency_key` is Dukavo's key; `external_id` is Meta's id.
        $existing = ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('direction', MessageDirection::Outbound)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof ConversationMessage && filled($existing->external_id)) {
            return [
                'conversation_id' => $conversation->getKey(),
                'message_id' => $existing->getKey(),
                'external_id' => $existing->external_id,
                'already_sent' => true,
            ];
        }

        $this->conversations->assertTextReplyAllowed($conversation);

        if ($conversation->channel === 'whatsapp') {
            $providerId = $this->gateway->sendText($conversation, (string) $payload['body'], $idempotencyKey);
            $message = ConversationMessage::query()
                ->where('conversation_id', $conversation->getKey())
                ->where('external_id', $providerId)
                ->first();

            return [
                'conversation_id' => $conversation->getKey(),
                'message_id' => $message?->getKey(),
                'external_id' => $providerId,
                'already_sent' => false,
            ];
        }

        $message = $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
            'user_id' => $actor->getKey(),
            'body' => (string) $payload['body'],
            'idempotency_key' => $idempotencyKey,
            'delivery_status' => 'sent',
        ]);

        return [
            'conversation_id' => $conversation->getKey(),
            'message_id' => $message->getKey(),
            'external_id' => $message->external_id,
            'already_sent' => ! $message->wasRecentlyCreated,
        ];
    }

    private function resolveConversation(mixed $conversationId): Conversation
    {
        $conversation = Conversation::query()->with('customer')->find((int) $conversationId);

        if (! $conversation instanceof Conversation) {
            throw BusinessRuleViolation::because('The proposed conversation does not exist in this workspace.');
        }

        if (! $conversation->isOpen()) {
            throw BusinessRuleViolation::because('The proposed conversation is closed.');
        }

        return $conversation;
    }

    private function stringOrNull(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
