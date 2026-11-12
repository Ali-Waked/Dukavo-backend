<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

/**
 * A provider delivery/read receipt translated into a monotonic status.
 *
 * The status string uses the conversation message vocabulary (`sent`,
 * `delivered`, `read`, `failed`) so the inbox does not need a provider switch.
 */
final readonly class NormalizedStatusUpdate
{
    public function __construct(
        public string $channel,
        public string $externalMessageId,
        public string $status,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
        public ?int $timestamp = null,
    ) {}

    public function eventKey(): string
    {
        return sprintf('status:%s:%s:%s', $this->externalMessageId, $this->status, (string) ($this->timestamp ?? ''));
    }
}
