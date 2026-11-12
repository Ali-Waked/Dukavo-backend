<?php

declare(strict_types=1);

namespace App\Services\Channels\Contracts;

use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Services\Channels\ChannelProviderRegistry;
use App\Services\Channels\Data\ChannelCapabilities;
use App\Services\Channels\Data\ConnectionTestResult;
use App\Services\Channels\Data\DownloadedMedia;
use App\Services\Channels\Data\NormalizedInboundMessage;
use App\Services\Channels\Data\NormalizedStatusUpdate;
use App\Services\Channels\Data\OutboundMessage;
use App\Services\Channels\Data\SentMessage;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use Illuminate\Http\Request;

/**
 * The boundary between the conversation engine and one provider.
 *
 * Every channel implements this contract and nothing else. The engine calls
 * only these methods, so a new provider is a new class registered in
 * {@see ChannelProviderRegistry} — it never changes the
 * conversation, customer or order code.
 */
interface ChannelConnector
{
    /**
     * The canonical channel key (`whatsapp`, `telegram`, `email` …).
     */
    public function channel(): string;

    /**
     * What this provider can actually do. The inbox and the voice pipeline
     * consult this before offering an action.
     */
    public function capabilities(): ChannelCapabilities;

    /**
     * Whether an inbound webhook delivery is authentic.
     *
     * Implementations fail closed: a missing secret refuses the delivery rather
     * than accepting it blind.
     */
    public function verifyWebhook(Request $request, ?ChannelConnection $connection = null): bool;

    /**
     * Resolve the tenant connection a webhook payload belongs to.
     *
     * The tenant is derived from the verified connection record, never from a
     * tenant id inside the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveConnection(Request $request, array $payload): ?ChannelConnection;

    /**
     * Translate a provider webhook payload into provider-neutral messages.
     *
     * @param  array<string, mixed>  $payload
     * @return list<NormalizedInboundMessage>
     */
    public function parseInbound(array $payload, ChannelConnection $connection): array;

    /**
     * Translate delivery/read receipts into provider-neutral status updates.
     *
     * @param  array<string, mixed>  $payload
     * @return list<NormalizedStatusUpdate>
     */
    public function parseStatus(array $payload, ChannelConnection $connection): array;

    /**
     * Deliver one outbound message and return the provider id.
     *
     * @throws ChannelDeliveryFailed
     */
    public function send(ChannelConnection $connection, Conversation $conversation, OutboundMessage $message): SentMessage;

    /**
     * Check that the stored credentials still work.
     */
    public function testConnection(ChannelConnection $connection): ConnectionTestResult;

    /**
     * Fetch an inbound attachment. Callers validate MIME type and size before
     * storing or serving the result.
     *
     * @throws ChannelDeliveryFailed
     */
    public function downloadMedia(ChannelConnection $connection, string $mediaId): DownloadedMedia;

    /**
     * Whether the connector writes the outbound conversation message itself.
     *
     * The existing WhatsApp gateway owns its persistence for historical
     * reasons; the dispatcher must not write a second row. New connectors
     * return false and let the dispatcher persist uniformly.
     */
    public function managesOwnPersistence(): bool;
}
