<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp\Webhooks;

use App\Enums\MessageType;

final readonly class InboundWhatsAppMessage implements WhatsAppWebhookEvent
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $eventKey,
        public string $messageId,
        public string $from,
        public ?string $profileName,
        public MessageType $type,
        public ?string $body,
        public ?string $mediaId,
        public ?string $mediaMime,
        public ?string $mediaFilename,
        public array $meta,
        public ?int $timestamp,
    ) {}

    public function eventKey(): string
    {
        return $this->eventKey;
    }

    public function eventType(): string
    {
        return 'message';
    }
}
