<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Channels\Data\OutboundMessage;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use App\Services\Conversations\ConversationService;

/**
 * The one outbound path shared by the inbox, automations and AI replies.
 *
 * Callers describe *what* to send; this class decides whether the channel can
 * send it, asks the connector to deliver it, and records the result once. It
 * never lets a second row appear for a retried idempotency key, and it never
 * records a message the provider refused.
 */
final class OutboundMessageDispatcher
{
    public function __construct(
        private readonly ChannelProviderRegistry $connectors,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * @throws ChannelDeliveryFailed
     */
    public function send(
        ChannelConnection $connection,
        Conversation $conversation,
        OutboundMessage $message,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): ConversationMessage {
        if ($conversation->channel !== $connection->channel) {
            throw BusinessRuleViolation::because(
                'The conversation and the connection belong to different channels.',
                ['conversation_channel' => $conversation->channel, 'connection_channel' => $connection->channel],
                'channel_mismatch',
            );
        }

        $connector = $this->connectors->for($connection->channel);
        $this->assertCapability($connection, $message);

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = ConversationMessage::query()
                ->where('conversation_id', $conversation->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof ConversationMessage) {
                return $existing;
            }
        }

        try {
            $sent = $connector->send($connection, $conversation, $message);
        } catch (ChannelDeliveryFailed $exception) {
            $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
                'user_id' => $userId,
                'type' => $message->type,
                'body' => $message->body,
                'idempotency_key' => $idempotencyKey,
                'delivery_status' => 'failed',
                'failure_code' => $exception->errorCode,
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
                'meta' => ['channel' => $connection->channel],
            ]);

            throw $exception;
        }

        if ($connector->managesOwnPersistence()) {
            $persisted = ConversationMessage::query()
                ->where('conversation_id', $conversation->getKey())
                ->where('external_id', $sent->externalId)
                ->first();

            if ($persisted instanceof ConversationMessage) {
                return $persisted;
            }
        }

        return $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
            'user_id' => $userId,
            'type' => $message->type,
            'body' => $message->body,
            'external_id' => $sent->externalId,
            'idempotency_key' => $idempotencyKey,
            'delivery_status' => $sent->status,
            'sent_at' => now(),
            'meta' => ['channel' => $connection->channel] + $message->meta + $sent->meta,
        ]);
    }

    /**
     * Refuse an action the provider cannot perform, before any bytes leave.
     */
    private function assertCapability(ChannelConnection $connection, OutboundMessage $message): void
    {
        $capability = $message->capability();
        $capabilities = $this->connectors->for($connection->channel)->capabilities()->toArray();

        if (($capabilities[$capability] ?? false) !== true) {
            throw ChannelDeliveryFailed::unsupported($connection->channel, $capability);
        }

        if ($message->type !== MessageType::Text && $message->media === null && $message->type !== MessageType::Template) {
            throw ChannelDeliveryFailed::fromProvider(
                $connection->channel,
                'missing_media',
                'No media was supplied for this message.',
            );
        }
    }
}
