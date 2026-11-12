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
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Meta Messaging adapter shared by Instagram and Facebook Messenger.
 *
 * Both products speak the Graph Send API and share the `X-Hub-Signature-256`
 * webhook signature, but they are different channels with different account
 * ids and capabilities. The channel key and object type are injected so the
 * registry can register two independent connectors from one implementation.
 */
final class MetaMessagingConnector implements ChannelConnector
{
    public function __construct(
        private readonly string $channel,
        private readonly string $objectType,
        private readonly WebhookSignatureVerifier $verifier,
    ) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function capabilities(): ChannelCapabilities
    {
        return $this->channel === 'instagram'
            ? new ChannelCapabilities(
                text: true, image: true, document: false, audio: true, voice: false,
                video: true, template: false, deliveryStatus: true, readStatus: true,
                mediaDownload: true,
            )
            : new ChannelCapabilities(
                text: true, image: true, document: true, audio: true, voice: false,
                video: true, template: false, deliveryStatus: true, readStatus: true,
                mediaDownload: true,
            );
    }

    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool
    {
        $secret = $connection?->credentials['app_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            $secret = config('services.meta.app_secret');
        }

        return $this->verifier->meta($request, is_string($secret) ? $secret : null);
    }

    public function resolveConnection(Request $request, array $payload): ?ChannelConnection
    {
        $routeConnection = $request->route('connection');

        if ($routeConnection instanceof ChannelConnection) {
            return $routeConnection->channel === $this->channel ? $routeConnection : null;
        }

        $accountId = (string) ($payload['entry'][0]['id'] ?? '');

        if ($accountId === '') {
            return null;
        }

        return ChannelConnection::query()
            ->acrossTenants()
            ->where('channel', $this->channel)
            ->where('external_account_id', $accountId)
            ->first();
    }

    public function parseInbound(array $payload, ChannelConnection $connection): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry['messaging'] ?? [] as $event) {
                if (is_array($event) && isset($event['message']) && is_array($event['message'])) {
                    $message = $this->fromMessaging($event, $connection);

                    if ($message instanceof NormalizedInboundMessage) {
                        $messages[] = $message;
                    }
                }
            }

            foreach ($entry['changes'] ?? [] as $change) {
                $value = is_array($change) ? ($change['value'] ?? null) : null;

                if (! is_array($value)) {
                    continue;
                }

                if (isset($value['message']) && is_array($value['message'])) {
                    $message = $this->fromMessaging($value, $connection);

                    if ($message instanceof NormalizedInboundMessage) {
                        $messages[] = $message;
                    }
                }
            }
        }

        return $messages;
    }

    public function parseStatus(array $payload, ChannelConnection $connection): array
    {
        $updates = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach (($entry['messaging'] ?? []) as $event) {
                if (! is_array($event)) {
                    continue;
                }

                foreach (($event['delivery']['mids'] ?? []) as $mid) {
                    $updates[] = new NormalizedStatusUpdate(
                        channel: $this->channel,
                        externalMessageId: (string) $mid,
                        status: 'delivered',
                        timestamp: isset($event['timestamp']) ? (int) $event['timestamp'] : null,
                    );
                }

                if (isset($event['read']['watermark']) && is_array($event['delivery']['mids'] ?? null)) {
                    foreach ($event['delivery']['mids'] as $mid) {
                        $updates[] = new NormalizedStatusUpdate(
                            channel: $this->channel,
                            externalMessageId: (string) $mid,
                            status: 'read',
                            timestamp: isset($event['timestamp']) ? (int) $event['timestamp'] : null,
                        );
                    }
                }
            }
        }

        return $updates;
    }

    public function send(ChannelConnection $connection, Conversation $conversation, OutboundMessage $message): SentMessage
    {
        $token = $this->token($connection);

        if ($token === null) {
            throw ChannelDeliveryFailed::notConfigured($this->channel);
        }

        $recipientId = $conversation->channel_account;

        if ($message->type === MessageType::Template) {
            throw ChannelDeliveryFailed::unsupported($this->channel, 'template');
        }

        if ($message->type === MessageType::Text) {
            return new SentMessage($this->messageId($this->post($token, $connection, [
                'recipient' => ['id' => $recipientId],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => (string) $message->body],
            ])));
        }

        $media = $message->media;

        if ($media === null) {
            throw ChannelDeliveryFailed::fromProvider($this->channel, 'missing_media', 'No media was supplied for this message.');
        }

        $attachmentId = $this->uploadAttachment($token, $connection, $media->contents, $media->filename, $media->mimeType);

        $attachmentType = match ($message->type) {
            MessageType::Image => 'image',
            MessageType::Audio => 'audio',
            MessageType::Video => 'video',
            default => 'file',
        };

        $payload = [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
            'message' => [
                'attachment' => [
                    'type' => $attachmentType,
                    'payload' => ['attachment_id' => $attachmentId],
                ],
            ],
        ];

        return new SentMessage($this->messageId($this->post($token, $connection, $payload)));
    }

    public function testConnection(ChannelConnection $connection): ConnectionTestResult
    {
        $token = $this->token($connection);
        $accountId = (string) $connection->external_account_id;

        if ($token === null || $accountId === '') {
            return ConnectionTestResult::failure('An account id and access token are required.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->get($this->graphUrl($accountId), ['fields' => 'id,name,username']);

            if (! $response->successful()) {
                return ConnectionTestResult::failure((string) ($response->json('error.message') ?? 'Meta rejected the credentials.'));
            }

            $name = $response->json('name') ?? $response->json('username');

            return ConnectionTestResult::success('Meta credentials are valid.', is_string($name) ? $name : null);
        } catch (Throwable $exception) {
            return ConnectionTestResult::failure($exception->getMessage());
        }
    }

    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia
    {
        $token = $this->token($connection);

        if ($token === null) {
            throw ChannelDeliveryFailed::notConfigured($this->channel);
        }

        // `mediaId` may be a direct CDN URL supplied by the webhook.
        $url = str_starts_with($mediaId, 'http') ? $mediaId : $this->graphUrl($mediaId);

        if (! str_starts_with($url, 'https://')) {
            throw ChannelDeliveryFailed::fromProvider($this->channel, 'untrusted_media_url', 'The media URL is not HTTPS.');
        }

        $response = Http::withToken($token)->timeout(60)->get($url);

        if (! $response->successful()) {
            throw ChannelDeliveryFailed::fromProvider($this->channel, 'media_download_failed', 'The attachment could not be downloaded.');
        }

        $contents = $response->body();
        $maxBytes = max(1, (int) config('services.meta.media_max_bytes', 25 * 1024 * 1024));

        if (strlen($contents) > $maxBytes) {
            throw ChannelDeliveryFailed::fromProvider($this->channel, 'media_too_large', 'The attachment exceeds the allowed size.');
        }

        return new DownloadedMedia(
            contents: $contents,
            mimeType: (string) ($response->header('Content-Type') ?: 'application/octet-stream'),
            size: strlen($contents),
        );
    }

    public function managesOwnPersistence(): bool
    {
        return false;
    }

    private function fromMessaging(array $event, ChannelConnection $connection): ?NormalizedInboundMessage
    {
        $message = $event['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $senderId = (string) ($event['sender']['id'] ?? '');
        $mid = (string) ($message['mid'] ?? '');

        if ($senderId === '' || $mid === '') {
            return null;
        }

        $type = MessageType::Text;
        $body = isset($message['text']) ? (string) $message['text'] : null;
        $mediaId = null;
        $mediaMime = null;

        $attachments = $message['attachments'] ?? [];

        if (is_array($attachments) && $attachments !== []) {
            $attachment = $attachments[0];
            $attachmentType = (string) ($attachment['type'] ?? '');

            $type = match ($attachmentType) {
                'image' => MessageType::Image,
                'audio' => MessageType::Audio,
                'video' => MessageType::Video,
                'file' => MessageType::File,
                default => MessageType::Text,
            };

            $mediaId = isset($attachment['payload']['url']) ? (string) $attachment['payload']['url'] : null;
            $mediaMime = $attachment['payload']['mime_type'] ?? null;
        }

        $name = $this->channel === 'instagram'
            ? (isset($event['sender']['username']) ? '@'.(string) $event['sender']['username'] : null)
            : null;

        return new NormalizedInboundMessage(
            channel: $this->channel,
            externalAccountId: (string) $connection->external_account_id,
            externalMessageId: $mid,
            senderId: $senderId,
            senderName: $name,
            type: $type,
            body: $body,
            mediaId: $mediaId,
            mediaMimeType: is_string($mediaMime) ? $mediaMime : null,
            timestamp: isset($event['timestamp']) ? (int) ($event['timestamp'] / 1000) : null,
            meta: [
                'provider' => 'meta',
                'object' => $this->objectType,
                'sender_id' => $senderId,
            ],
        );
    }

    private function token(ChannelConnection $connection): ?string
    {
        $token = $connection->credentials['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function post(string $token, ChannelConnection $connection, array $payload): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->post($this->graphUrl((string) $connection->external_account_id).'/messages', $payload);
    }

    private function uploadAttachment(string $token, ChannelConnection $connection, string $contents, string $filename, string $mimeType): string
    {
        $response = Http::withToken($token)
            ->connectTimeout(5)
            ->timeout(60)
            ->attach('filedata', $contents, $filename, ['Content-Type' => $mimeType])
            ->post($this->graphUrl((string) $connection->external_account_id).'/message_attachments', [
                'message' => json_encode([
                    'attachment' => [
                        'type' => str_starts_with($mimeType, 'image/') ? 'image' : 'file',
                        'payload' => ['is_reusable' => true],
                    ],
                ]),
            ]);

        $attachmentId = $response->json('attachment_id');

        if (! is_string($attachmentId) || $attachmentId === '') {
            throw ChannelDeliveryFailed::fromProvider($this->channel, (string) $response->status(), (string) ($response->json('error.message') ?? 'Attachment upload failed.'));
        }

        return $attachmentId;
    }

    private function messageId(Response $response): string
    {
        if (! $response->successful()) {
            throw ChannelDeliveryFailed::fromProvider($this->channel, (string) $response->status(), (string) ($response->json('error.message') ?? 'Meta rejected the message.'));
        }

        $messageId = $response->json('message_id');

        if (! is_string($messageId) || $messageId === '') {
            throw ChannelDeliveryFailed::fromProvider($this->channel, 'missing_message_id', 'Meta did not return a message id.');
        }

        return $messageId;
    }

    private function graphUrl(string $id): string
    {
        $version = (string) (config('services.meta.graph_version') ?: 'v26.0');
        $host = rtrim((string) (config('services.meta.graph_url') ?: 'https://graph.facebook.com'), '/');

        return sprintf('%s/%s/%s', $host, ltrim($version, '/'), ltrim($id, '/'));
    }
}
