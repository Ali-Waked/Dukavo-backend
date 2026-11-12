<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

/**
 * The provider's acknowledgement of a sent message.
 */
final readonly class SentMessage
{
    public function __construct(
        public string $externalId,
        public string $status = 'sent',
        public array $meta = [],
    ) {}
}
