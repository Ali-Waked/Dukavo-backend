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
use App\Services\Channels\Data\OutboundMessage;
use App\Services\Channels\Data\SentMessage;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use App\Services\Channels\WebhookSignatureVerifier;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram Bot API adapter.
 *
 * A bot is identified by its token; inbound updates are routed to the right
 * tenant by the connection id in the webhook URL and authenticated with the
 * secret token Telegram echoes back. Telegram bots do not receive read receipts,
 * so `readStatus` is false rather than optimistically claimed.
 */
final class TelegramConnector implements ChannelConnector
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier) {}

    public function channel(): string
    {
        return 'telegram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            text: true, image: true, document: true, audio: true, voice: true,
            video: true, template: false, deliveryStatus: true, readStatus: false,
            mediaDownload: true, typing: true,
        );
    }

    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool
    {
        $secret = $connection?->credentials['webhook_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            $secret = config('services.telegram.webhook_secret');
        }

        return $this->verifier->telegram($request, is_string($secret) ? $secret : null);
    }

    public function resolveConnection(Request $request, array $payload): ?ChannelConnection
    {
        $routeConnection = $request->route('connection');

        if ($routeConnection instanceof ChannelConnection) {
            return $routeConnection->channel === 'telegram' ? $routeConnection : null;
        }

        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($secret !== '') {
            $hash = hash('sha256', $secret);

            $connection = ChannelConnection::query()
                ->acrossTenants()
                ->where('channel', 'telegram')
                ->where('meta->webhook_secret_hash', $hash)
                ->first();

            if ($connection instanceof ChannelConnection) {
                return $connection;
            }
        }

        return null;
    }

    public function parseInbound(array $payload, ChannelConnection $connection): array
    {
        $message = $payload['message'] ?? $payload['edited_message'] ?? null;

        if (! is_array($message)) {
            return [];
        }

        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if ($chatId === null || $messageId === null) {
            return [];
        }

        $type = MessageType::Text;
        $body = $message['text'] ?? $message['caption'] ?? null;
        $mediaId = null;
        $mediaMime = null;
        $filename = null;

        if (isset($message['photo']) && is_array($message['photo']) && $message['photo'] !== []) {
            $type = MessageType::Image;
            $photo = end($message['photo']);
            $mediaId = isset($photo['file_id']) ? (string) $photo['file_id'] : null;
            $mediaMime = 'image/jpeg';
        } elseif (isset($message['document']) && is_array($message['document'])) {
            $type = MessageType::File;
            $mediaId = isset($message['document']['file_id']) ? (string) $message['document']['file_id'] : null;
            $mediaMime = isset($message['document']['mime_type']) ? (string) $message['document']['mime_type'] : null;
            $filename = isset($message['document']['file_name']) ? (string) $message['document']['file_name'] : null;
        } elseif (isset($message['audio']) && is_array($message['audio'])) {
            $type = MessageType::Audio;
            $mediaId = isset($message['audio']['file_id']) ? (string) $message['audio']['file_id'] : null;
            $mediaMime = isset($message['audio']['mime_type']) ? (string) $message['audio']['mime_type'] : null;
            $filename = isset($message['audio']['file_name']) ? (string) $message['audio']['file_name'] : null;
        } elseif (isset($message['voice']) && is_array($message['voice'])) {
            $type = MessageType::Audio;
            $mediaId = isset($message['voice']['file_id']) ? (string) $message['voice']['file_id'] : null;
            $mediaMime = isset($message['voice']['mime_type']) ? (string) $message['voice']['mime_type'] : 'audio/ogg';
        } elseif (isset($message['video']) && is_array($message['video'])) {
            $type = MessageType::Video;
            $mediaId = isset($message['video']['file_id']) ? (string) $message['video']['file_id'] : null;
            $mediaMime = isset($message['video']['mime_type']) ? (string) $message['video']['mime_type'] : null;
            $filename = isset($message['video']['file_name']) ? (string) $message['video']['file_name'] : null;
        }

        if ($body === null && $mediaId === null) {
            return [];
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $name = trim(implode(' ', array_filter([
            isset($from['first_name']) ? (string) $from['first_name'] : null,
            isset($from['last_name']) ? (string) $from['last_name'] : null,
        ])));

        if ($name === '' && isset($from['username'])) {
            $name = '@'.(string) $from['username'];
        }

        return [
            new NormalizedInboundMessage(
                channel: 'telegram',
                externalAccountId: (string) $connection->external_account_id,
                externalMessageId: sprintf('telegram:%s:%s', (string) $chatId, (string) $messageId),
                senderId: (string) $chatId,
                senderName: $name !== '' ? $name : null,
                type: $type,
                body: is_string($body) ? $body : null,
                mediaId: $mediaId,
                mediaMimeType: $mediaMime,
                mediaFilename: $filename,
                timestamp: isset($message['date']) ? (int) $message['date'] : null,
                meta: [
                    'provider' => 'telegram',
                    'chat_id' => (string) $chatId,
                    'message_id' => (string) $messageId,
                    'username' => $from['username'] ?? null,
                ],
            ),
        ];
    }

    public function parseStatus(array $payload, ChannelConnection $connection): array
    {
        // Telegram bots do not receive delivery or read receipts.
        return [];
    }

    public function send(ChannelConnection $connection, Conversation $conversation, OutboundMessage $message): SentMessage
    {
        $token = $this->token($connection);

        if ($token === null) {
            throw ChannelDeliveryFailed::notConfigured('telegram');
        }

        $chatId = $conversation->channel_account;

        if ($message->type === MessageType::Template) {
            throw ChannelDeliveryFailed::unsupported('telegram', 'template');
        }

        if ($message->type === MessageType::Text) {
            $response = $this->client($token)->post($this->url($token, 'sendMessage'), [
                'chat_id' => $chatId,
                'text' => (string) $message->body,
            ]);

            return new SentMessage($this->messageId($response));
        }

        $media = $message->media;

        if ($media === null) {
            throw ChannelDeliveryFailed::fromProvider('telegram', 'missing_media', 'No media was supplied for this message.');
        }

        $method = match ($message->type) {
            MessageType::Image => 'sendPhoto',
            MessageType::Audio => $media->asVoiceNote ? 'sendVoice' : 'sendAudio',
            MessageType::Video => 'sendVideo',
            default => 'sendDocument',
        };

        $field = match ($method) {
            'sendPhoto' => 'photo',
            'sendVoice' => 'voice',
            'sendAudio' => 'audio',
            'sendVideo' => 'video',
            default => 'document',
        };

        $response = Http::connectTimeout(5)
            ->timeout(60)
            ->attach($field, $media->contents, $media->filename, ['Content-Type' => $media->mimeType])
            ->post($this->url($token, $method), array_filter([
                'chat_id' => $chatId,
                'caption' => $message->body,
            ], static fn (mixed $value): bool => $value !== null));

        return new SentMessage($this->messageId($response));
    }

    public function testConnection(ChannelConnection $connection): ConnectionTestResult
    {
        $token = $this->token($connection);

        if ($token === null) {
            return ConnectionTestResult::failure('A bot token is required.');
        }

        try {
            $response = $this->client($token)->get($this->url($token, 'getMe'));

            if (! $response->successful() || $response->json('ok') !== true) {
                return ConnectionTestResult::failure((string) ($response->json('description') ?? 'Telegram rejected the bot token.'));
            }

            $username = $response->json('result.username');

            return ConnectionTestResult::success('Telegram bot is reachable.', is_string($username) ? '@'.$username : null);
        } catch (Throwable $exception) {
            return ConnectionTestResult::failure($exception->getMessage());
        }
    }

    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia
    {
        $token = $this->token($connection);

        if ($token === null) {
            throw ChannelDeliveryFailed::notConfigured('telegram');
        }

        $info = $this->client($token)->get($this->url($token, 'getFile'), ['file_id' => $mediaId]);

        if (! $info->successful() || $info->json('ok') !== true) {
            throw ChannelDeliveryFailed::fromProvider('telegram', 'media_lookup_failed', 'Telegram could not resolve the file.');
        }

        $filePath = (string) $info->json('result.file_path', '');
        $fileSize = (int) $info->json('result.file_size', 0);
        $maxBytes = max(1, (int) config('services.telegram.media_max_bytes', 20 * 1024 * 1024));

        if ($filePath === '') {
            throw ChannelDeliveryFailed::fromProvider('telegram', 'media_lookup_failed', 'Telegram returned no file path.');
        }

        if ($fileSize > $maxBytes) {
            throw ChannelDeliveryFailed::fromProvider('telegram', 'media_too_large', 'The file exceeds the allowed size.');
        }

        $download = Http::timeout(60)->get($this->fileUrl($token, $filePath));

        if (! $download->successful()) {
            throw ChannelDeliveryFailed::fromProvider('telegram', 'media_download_failed', 'The file could not be downloaded.');
        }

        $contents = $download->body();

        return new DownloadedMedia(
            contents: $contents,
            mimeType: (string) ($download->header('Content-Type') ?: 'application/octet-stream'),
            size: strlen($contents),
            filename: basename($filePath),
        );
    }

    public function managesOwnPersistence(): bool
    {
        return false;
    }

    private function token(ChannelConnection $connection): ?string
    {
        $token = $connection->credentials['bot_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function client(string $token): PendingRequest
    {
        return Http::acceptJson()->asJson()->connectTimeout(5)->timeout(30);
    }

    private function url(string $token, string $method): string
    {
        $base = rtrim((string) (config('services.telegram.api_base_url') ?: 'https://api.telegram.org'), '/');

        return sprintf('%s/bot%s/%s', $base, $token, $method);
    }

    private function fileUrl(string $token, string $filePath): string
    {
        $base = rtrim((string) (config('services.telegram.file_base_url') ?: 'https://api.telegram.org'), '/');

        return sprintf('%s/file/bot%s/%s', $base, $token, ltrim($filePath, '/'));
    }

    private function messageId(Response $response): string
    {
        if (! $response->successful() || $response->json('ok') !== true) {
            throw ChannelDeliveryFailed::fromProvider('telegram', (string) $response->status(), (string) ($response->json('description') ?? 'Telegram rejected the message.'));
        }

        $messageId = $response->json('result.message_id');

        if ($messageId === null) {
            throw ChannelDeliveryFailed::fromProvider('telegram', 'missing_message_id', 'Telegram did not return a message id.');
        }

        return (string) $messageId;
    }
}
