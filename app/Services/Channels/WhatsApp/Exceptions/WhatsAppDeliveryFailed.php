<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp\Exceptions;

use App\Exceptions\BusinessRuleViolation;

/**
 * The WhatsApp Cloud API refused or failed a request.
 *
 * Provider responses are logged with the request id but never surfaced verbatim
 * to merchants, so a validation error from Meta cannot leak internal detail into
 * the UI while the operator still gets what they need to debug it.
 */
class WhatsAppDeliveryFailed extends BusinessRuleViolation
{
    public static function fromProvider(string $operation, int $status, ?string $providerMessage = null, ?string $requestId = null): self
    {
        return new self(
            'WhatsApp rejected the request. Check the account connection and try again.',
            [
                'operation' => $operation,
                'provider_status' => $status,
                'provider_message' => $providerMessage,
                'request_id' => $requestId,
            ],
            'whatsapp_delivery_failed',
        );
    }

    public static function missingCredentials(string $operation): self
    {
        return new self(
            'This WhatsApp account is not fully connected yet.',
            ['operation' => $operation],
            'whatsapp_not_connected',
        );
    }
}
