<?php

declare(strict_types=1);

namespace App\Services\Channels\Connectors;

use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Services\Channels\Contracts\ChannelConnector;
use App\Services\Channels\Data\ChannelCapabilities;
use App\Services\Channels\Data\ConnectionTestResult;
use App\Services\Channels\Data\DownloadedMedia;
use App\Services\Channels\Data\OutboundMessage;
use App\Services\Channels\Data\SentMessage;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use App\Services\Channels\WebhookSignatureVerifier;
use Illuminate\Http\Request;

/**
 * TikTok Business Messaging adapter — awaiting provider access.
 *
 * TikTok grants Business Messaging API access per partner. Until that access is
 * approved there is no documented send endpoint to call, so this adapter
 * refuses every operation with an explicit reason instead of pretending to
 * work. The capability matrix still describes what the channel will support,
 * and the integration centre renders it as unavailable.
 *
 * When access is granted, `send`, `parseInbound` and `parseStatus` become real
 * implementations; nothing else in the engine changes.
 */
final class TikTokConnector implements ChannelConnector
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier) {}

    public function channel(): string
    {
        return 'tiktok';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(text: true);
    }

    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool
    {
        $secret = $connection?->credentials['client_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            $secret = config('services.tiktok.client_secret');
        }

        return $this->verifier->hexHmac($request, is_string($secret) ? $secret : null, 'X-TikTok-Signature');
    }

    public function resolveConnection(Request $request, array $payload): ?ChannelConnection
    {
        return null;
    }

    public function parseInbound(array $payload, ChannelConnection $connection): array
    {
        return [];
    }

    public function parseStatus(array $payload, ChannelConnection $connection): array
    {
        return [];
    }

    public function send(ChannelConnection $connection, Conversation $conversation, OutboundMessage $message): SentMessage
    {
        throw ChannelDeliveryFailed::unavailable(
            'tiktok',
            'TikTok Business Messaging access has not been granted to this Dukavo installation. The channel cannot send until TikTok approves the partner application.',
        );
    }

    public function testConnection(ChannelConnection $connection): ConnectionTestResult
    {
        return ConnectionTestResult::failure(
            'TikTok Business Messaging access is awaiting provider approval. Credentials can be stored, but the channel cannot be verified or used yet.',
        );
    }

    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia
    {
        throw ChannelDeliveryFailed::unsupported('tiktok', 'media download');
    }

    public function managesOwnPersistence(): bool
    {
        return false;
    }
}
