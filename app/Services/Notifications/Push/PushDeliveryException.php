<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

use RuntimeException;

/**
 * A push attempt that failed in a way the caller must understand.
 *
 * A permanent failure (an unregistered or malformed token) must not be retried
 * three times: the token will never become valid and the device should be
 * revoked. A transient failure is worth a queue retry with backoff.
 */
final class PushDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, true, $previous);
    }

    public static function temporary(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, false, $previous);
    }
}
