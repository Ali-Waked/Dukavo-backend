<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

/**
 * The messaging capabilities a channel actually supports.
 *
 * Providers do not offer the same feature set. Voice notes, read receipts and
 * templates are not universal, so the conversation engine asks this object
 * before offering an action instead of assuming parity.
 */
final readonly class ChannelCapabilities
{
    public function __construct(
        public bool $text = true,
        public bool $image = false,
        public bool $document = false,
        public bool $audio = false,
        public bool $voice = false,
        public bool $video = false,
        public bool $template = false,
        public bool $deliveryStatus = false,
        public bool $readStatus = false,
        public bool $mediaDownload = false,
        public bool $typing = false,
    ) {}

    /**
     * @param  array<string, bool>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            text: (bool) ($values['text'] ?? true),
            image: (bool) ($values['image'] ?? false),
            document: (bool) ($values['document'] ?? false),
            audio: (bool) ($values['audio'] ?? false),
            voice: (bool) ($values['voice'] ?? false),
            video: (bool) ($values['video'] ?? false),
            template: (bool) ($values['template'] ?? false),
            deliveryStatus: (bool) ($values['delivery_status'] ?? false),
            readStatus: (bool) ($values['read_status'] ?? false),
            mediaDownload: (bool) ($values['media_download'] ?? false),
            typing: (bool) ($values['typing'] ?? false),
        );
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'image' => $this->image,
            'document' => $this->document,
            'audio' => $this->audio,
            'voice' => $this->voice,
            'video' => $this->video,
            'template' => $this->template,
            'delivery_status' => $this->deliveryStatus,
            'read_status' => $this->readStatus,
            'media_download' => $this->mediaDownload,
            'typing' => $this->typing,
        ];
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return array_keys(array_filter($this->toArray()));
    }
}
