<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Models\ChannelConnection;
use App\Services\Channels\Contracts\ChannelConnector;
use App\Services\Channels\Data\NormalizedInboundMessage;
use App\Services\Channels\Data\NormalizedStatusUpdate;

/**
 * The single entry point for turning provider payloads into engine events.
 *
 * Connectors know their own payload shape; this class keeps that knowledge out
 * of the webhook controller and gives one place to add cross-channel concerns
 * (deduplication, validation, future media pre-checks).
 */
final class MessageNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<NormalizedInboundMessage>
     */
    public function inbound(ChannelConnector $connector, array $payload, ChannelConnection $connection): array
    {
        return array_values(array_filter(
            $connector->parseInbound($payload, $connection),
            static fn (mixed $message): bool => $message instanceof NormalizedInboundMessage
                && $message->externalMessageId !== ''
                && $message->senderId !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<NormalizedStatusUpdate>
     */
    public function status(ChannelConnector $connector, array $payload, ChannelConnection $connection): array
    {
        return array_values(array_filter(
            $connector->parseStatus($payload, $connection),
            static fn (mixed $update): bool => $update instanceof NormalizedStatusUpdate
                && $update->externalMessageId !== '',
        ));
    }
}
