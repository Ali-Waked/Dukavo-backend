<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

use App\Enums\MessageType;

/**
 * One outbound message a connector must deliver.
 *
 * A connector receives only this object; it never reads a controller request,
 * which is what lets the same send path serve the inbox, an automation and an
 * AI reply.
 */
final readonly class OutboundMessage
{
    public function __construct(
        public MessageType $type,
        public ?string $body = null,
        public ?OutboundMedia $media = null,
        public ?string $templateName = null,
        public ?string $templateLanguage = null,
        public array $templateParameters = [],
        public array $meta = [],
    ) {}

    public static function text(string $body): self
    {
        return new self(MessageType::Text, $body);
    }

    public static function media(MessageType $type, OutboundMedia $media, ?string $caption = null): self
    {
        return new self($type, $caption, $media);
    }

    public static function template(string $name, string $language, array $parameters = []): self
    {
        return new self(
            MessageType::Template,
            body: $name,
            templateName: $name,
            templateLanguage: $language,
            templateParameters: $parameters,
        );
    }

    public function capability(): string
    {
        return match ($this->type) {
            MessageType::Image => 'image',
            MessageType::Audio => 'audio',
            MessageType::Video => 'video',
            MessageType::File => 'document',
            MessageType::Template => 'template',
            default => 'text',
        };
    }
}
