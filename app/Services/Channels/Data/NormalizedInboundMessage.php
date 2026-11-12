<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

use App\Enums\MessageType;

/**
 * A provider message translated into Dukavo's vocabulary.
 *
 * Everything downstream — customer identity, conversation, voice pipeline —
 * consumes this shape, so adding a channel never leaks its payload structure
 * into the conversation engine.
 */
final readonly class NormalizedInboundMessage
{
    public function __construct(
        public string $channel,
        public string $externalAccountId,
        public string $externalMessageId,
        public string $senderId,
        public ?string $senderName,
        public MessageType $type,
        public ?string $body,
        public ?string $mediaId = null,
        public ?string $mediaMimeType = null,
        public ?string $mediaFilename = null,
        public ?int $timestamp = null,
        public array $meta = [],
    ) {}

    /**
     * Stable idempotency key for the webhook ledger.
     */
    public function eventKey(): string
    {
        return sprintf('message:%s', $this->externalMessageId);
    }

    public function isMedia(): bool
    {
        return $this->mediaId !== null && $this->type->isMedia();
    }

    public function isAudio(): bool
    {
        return $this->type === MessageType::Audio;
    }
}
