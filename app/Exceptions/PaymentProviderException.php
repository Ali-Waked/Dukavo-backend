<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A provider call did not produce a usable answer.
 *
 * The message is safe to show to a merchant; the context is for logs and must
 * never contain a credential. A provider failure is not a business rule
 * violation: the request was valid, the gateway was not available or refused it.
 */
class PaymentProviderException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [], private readonly ?int $providerStatus = null)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function because(string $message, array $context = [], ?int $providerStatus = null): self
    {
        return new self($message, $context, $providerStatus);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function providerStatus(): ?int
    {
        return $this->providerStatus;
    }
}
