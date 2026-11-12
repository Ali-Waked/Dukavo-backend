<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

/**
 * Media that will be uploaded to a provider.
 *
 * `contents` is the raw bytes; connectors that support multipart uploads stream
 * it directly. `filename` and `mimeType` are always set by the server, never by
 * a browser.
 */
final readonly class OutboundMedia
{
    public function __construct(
        public string $contents,
        public string $filename,
        public string $mimeType,
        public bool $asVoiceNote = false,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
