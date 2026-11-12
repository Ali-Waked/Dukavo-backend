<?php

declare(strict_types=1);

namespace App\Services\Channels\Data;

/**
 * Bytes fetched from a provider, validated by the caller before storage.
 */
final readonly class DownloadedMedia
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public int $size,
        public ?string $filename = null,
    ) {}
}
