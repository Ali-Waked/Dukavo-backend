<?php

declare(strict_types=1);

namespace App\Services\Channels\Connectors;

use App\Enums\MessageType;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Services\Channels\Contracts\ChannelConnector;
use App\Services\Channels\Data\ChannelCapabilities;
use App\Services\Channels\Data\ConnectionTestResult;
use App\Services\Channels\Data\DownloadedMedia;
use App\Services\Channels\Data\NormalizedInboundMessage;
use App\Services\Channels\Data\NormalizedStatusUpdate;
use App\Services\Channels\Data\OutboundMessage;
use App\Services\Channels\Data\SentMessage;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use App\Services\Channels\WebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Generic HTTP SMS gateway.
 *
 * SMS providers do not share one API, so this connector speaks a small,
 * documented JSON contract: `{to, message, sender_id}` in, `{id}` out. A
 * merchant points it at a gateway that implements that contract. It is marked
 * `awaiting_configuration` until an endpoint is supplied — never advertised as
 * working without one.
 */
final class SmsConnector implements ChannelConnector
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier) {}

    public function channel(): string
    {
        return 'sms';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            text: true, deliveryStatus: true,
        );
    }

    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool
    {
        $secret = $connection?->credentials['webhook_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            $secret = config('services.sms.webhook_secret');
        }

        return $this->verifier->sharedSecret($request, is_string($secret) ? $secret : null);
    }

    public function resolveConnection(Request $request, array $payload): ?ChannelConnection
    {
        $routeConnection = $request->route('connection');

        if ($routeConnection instanceof ChannelConnection) {
            return $routeConnection->channel === 'sms' ? $routeConnection : null;
        }

        return null;
    }

    public function parseInbound(array $payload, ChannelConnection $connection): array
    {
        $from = (string) ($payload['from'] ?? $payload['sender'] ?? '');
        $messageId = (string) ($payload['message_id'] ?? $payload['id'] ?? '');

        if ($from === '' || $messageId === '') {
            return [];
        }

        return [
            new NormalizedInboundMessage(
                channel: 'sms',
                externalAccountId: (string) $connection->external_account_id,
                externalMessageId: 'sms:'.$messageId,
                senderId: $from,
                senderName: isset($payload['from_name']) ? (string) $payload['from_name'] : null,
                type: MessageType::Text,
                body: isset($payload['text']) ? (string) $payload['text'] : null,
                timestamp: isset($payload['timestamp']) ? (int) $payload['timestamp'] : null,
                meta: ['provider' => 'sms', 'message_id' => $messageId],
            ),
        ];
    }

    public function parseStatus(array $payload, ChannelConnection $connection): array
    {
        $messageId = (string) ($payload['message_id'] ?? $payload['id'] ?? '');

        if ($messageId === '') {
            return [];
        }

        $status = strtolower((string) ($payload['status'] ?? 'delivered'));

        return [
            new NormalizedStatusUpdate(
                channel: 'sms',
                externalMessageId: 'sms:'.$messageId,
                status: in_array($status, ['delivered', 'failed', 'sent'], true) ? $status : 'delivered',
                failureReason: isset($payload['reason']) ? (string) $payload['reason'] : null,
                timestamp: isset($payload['timestamp']) ? (int) $payload['timestamp'] : null,
            ),
        ];
    }

    public function send(ChannelConnection $connection, Conversation $conversation, OutboundMessage $message): SentMessage
    {
        if ($message->type !== MessageType::Text) {
            throw ChannelDeliveryFailed::unsupported('sms', $message->capability());
        }

        $endpoint = (string) ($connection->credentials['endpoint'] ?? '');
        $apiKey = (string) ($connection->credentials['api_key'] ?? '');

        if ($endpoint === '' || $apiKey === '') {
            throw ChannelDeliveryFailed::notConfigured('sms');
        }

        if (! str_starts_with($endpoint, 'https://')) {
            throw ChannelDeliveryFailed::fromProvider('sms', 'insecure_endpoint', 'The SMS endpoint must use HTTPS.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post($endpoint, array_filter([
                    'to' => $conversation->channel_account,
                    'message' => (string) $message->body,
                    'sender_id' => $connection->credentials['sender_id'] ?? null,
                ], static fn (mixed $value): bool => $value !== null));
        } catch (Throwable $exception) {
            throw ChannelDeliveryFailed::fromProvider('sms', 'send_failed', 'The SMS gateway could not be reached: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            throw ChannelDeliveryFailed::fromProvider('sms', (string) $response->status(), (string) ($response->json('message') ?? 'The SMS gateway rejected the message.'));
        }

        $messageId = $response->json('id') ?? $response->json('message_id');

        if (! is_string($messageId) || $messageId === '') {
            throw ChannelDeliveryFailed::fromProvider('sms', 'missing_message_id', 'The SMS gateway did not return a message id.');
        }

        return new SentMessage('sms:'.$messageId);
    }

    public function testConnection(ChannelConnection $connection): ConnectionTestResult
    {
        $endpoint = (string) ($connection->credentials['endpoint'] ?? '');
        $apiKey = (string) ($connection->credentials['api_key'] ?? '');

        if ($endpoint === '' || $apiKey === '') {
            return ConnectionTestResult::failure('An endpoint and API key are required.');
        }

        if (! str_starts_with($endpoint, 'https://')) {
            return ConnectionTestResult::failure('The SMS endpoint must use HTTPS.');
        }

        return ConnectionTestResult::success('SMS gateway configuration is present.');
    }

    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia
    {
        throw ChannelDeliveryFailed::unsupported('sms', 'media download');
    }

    public function managesOwnPersistence(): bool
    {
        return false;
    }
}
