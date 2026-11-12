<?php

declare(strict_types=1);

namespace App\Services\Channels\Exceptions;

use RuntimeException;

/**
 * A provider could not accept an outbound message, or refused a webhook.
 *
 * The message is safe to show to a merchant: it never contains a credential.
 */
final class ChannelDeliveryFailed extends RuntimeException
{
    public function __construct(
        public readonly string $channel,
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $channel): self
    {
        return new self($channel, 'not_configured', 'This channel is not configured for the workspace.');
    }

    public static function unavailable(string $channel, string $reason): self
    {
        return new self($channel, 'unavailable', $reason);
    }

    public static function unsupported(string $channel, string $capability): self
    {
        return new self($channel, 'unsupported_capability', sprintf('%s does not support %s messages.', ucfirst($channel), $capability));
    }

    public static function fromProvider(string $channel, string $code, string $message, array $context = []): self
    {
        return new self($channel, $code, $message, $context);
    }
}
