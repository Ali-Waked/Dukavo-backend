<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\SpeechTranscription;

/**
 * One audio file in, one transcript out.
 *
 * Detection is deliberately separate from the chat-provider abstraction: a
 * deployment may use Whisper for audio and a different model for structured
 * extraction. Implementations must never log audio, credentials or transcripts.
 */
interface SpeechToTextProvider
{
    public function provider(): string;

    public function isConfigured(): bool;

    public function transcribe(
        string $contents,
        string $filename,
        string $mimeType,
        ?string $language = null,
    ): SpeechTranscription;
}
