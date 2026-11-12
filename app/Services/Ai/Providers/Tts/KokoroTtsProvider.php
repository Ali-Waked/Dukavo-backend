<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\Tts;

/**
 * A self-hosted Kokoro engine exposed through an OpenAI-compatible endpoint.
 *
 * Kokoro is the second free-first option: like Piper it runs on the
 * installation's own hardware. The adapter tolerates a deployment that puts an
 * auth proxy in front of it, but never requires a paid key.
 */
final class KokoroTtsProvider extends OpenAiCompatibleTtsProvider
{
    public function provider(): string
    {
        return 'kokoro';
    }

    public function isPaid(): bool
    {
        return false;
    }

    protected function requiresKey(): bool
    {
        return false;
    }
}
