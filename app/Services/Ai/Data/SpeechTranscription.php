<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * A transcription plus only the metadata worth persisting.
 *
 * The raw provider response and audio are deliberately not carried out of the
 * adapter.
 */
final readonly class SpeechTranscription
{
    public function __construct(
        public string $text,
        public ?string $language = null,
        public ?float $durationSeconds = null,
        public ?float $confidence = null,
        public string $model = '',
        public int $latencyMs = 0,
        public ?string $providerResponseId = null,
    ) {}
}
