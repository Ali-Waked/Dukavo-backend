<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\SpeechSynthesis;

/**
 * One piece of text in, one audio file out.
 *
 * Text-to-speech is separate from chat completions because the free-first
 * deployment runs a self-hosted engine (Piper or Kokoro) with no paid key at
 * all. Implementations must never log the text, the audio or a credential, and
 * must report honestly when they are not configured instead of returning
 * silence.
 */
interface TextToSpeechProvider
{
    /**
     * Stable provider key used in configuration and usage rows.
     */
    public function provider(): string;

    /**
     * Whether this adapter has everything it needs to synthesise right now.
     */
    public function isConfigured(): bool;

    /**
     * Whether a call costs money.
     *
     * The factory refuses to select a paid adapter unless the deployment has
     * explicitly authorised paid providers, so a free-first installation can
     * never start billing by accident.
     */
    public function isPaid(): bool;

    public function synthesize(string $text, ?string $voice = null, ?string $format = null): SpeechSynthesis;
}
