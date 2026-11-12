<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

/**
 * The result of a connection test, shown verbatim in the integration centre.
 */
final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $accountName = null,
        public array $meta = [],
    ) {}

    public static function success(string $message, ?string $accountName = null, array $meta = []): self
    {
        return new self(true, $message, $accountName, $meta);
    }

    public static function failure(string $message, array $meta = []): self
    {
        return new self(false, $message, null, $meta);
    }
}
