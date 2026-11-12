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
 * X (Twitter) Direct Message adapter — awaiting provider access.
 *
 * Direct Message access requires an elevated X API plan that this installation
 * has not been granted. The adapter verifies the documented webhook signature
 * so an operator can already register the webhook, but it refuses to send and
 * returns no messages until access is approved. The integration centre labels
 * the channel "awaiting provider access" rather than showing a dead button.
 */
final class XConnector implements ChannelConnector
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier) {}

    public function channel(): string
    {
        return 'x';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            text: true, image: true, deliveryStatus: true, mediaDownload: true,
        );
    }

    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool
    {
        $secret = $connection?->credentials['api_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            $secret = config('services.x.api_secret');
        }

        return $this->verifier->xTwitter($request, is_string($secret) ? $secret : null);
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
            'x',
            'X Direct Message access requires an elevated API plan that has not been granted to this Dukavo installation.',
        );
    }

    public function testConnection(ChannelConnection $connection): ConnectionTestResult
    {
        return ConnectionTestResult::failure(
            'X Direct Message access is awaiting provider approval. Credentials can be stored, but the channel cannot be verified or used yet.',
        );
    }

    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia
    {
        throw ChannelDeliveryFailed::unsupported('x', 'media download');
    }

    public function managesOwnPersistence(): bool
    {
        return false;
    }
}
