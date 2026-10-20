<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * A single successful model answer plus the metadata worth recording.
 *
 * The raw provider payload is deliberately not kept: only the text (or the
 * decoded structure), the token counts, the latency and the provider's own
 * response id travel out of the client. Nothing here should ever contain a
 * secret, and callers must treat `text`/`structured` as untrusted input.
 */
final readonly class AiCompletion
{
    /**
     * @param  array<string, mixed>|null  $structured  Decoded JSON when a schema was requested.
     */
    public function __construct(
        public string $text,
        public ?array $structured = null,
        public string $model = '',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $totalTokens = 0,
        public int $latencyMs = 0,
        public ?string $providerResponseId = null,
    ) {}
}
