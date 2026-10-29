<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp\Webhooks;

final readonly class WhatsAppDeliveryReceipt implements WhatsAppWebhookEvent
{
    public function __construct(
        public string $eventKey,
        public string $messageId,
        public string $state,
        public ?string $failureReason,
        public ?string $failureCode,
        public ?int $timestamp,
    ) {}

    public function eventKey(): string
    {
        return $this->eventKey;
    }

    public function eventType(): string
    {
        return 'status';
    }
}
