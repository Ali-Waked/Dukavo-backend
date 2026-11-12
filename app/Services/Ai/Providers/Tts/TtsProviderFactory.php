<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\Tts;

use App\Services\Ai\Contracts\TextToSpeechProvider;

/**
 * Builds the configured text-to-speech adapter.
 *
 * Selection is a single decision made here, never scattered through callers.
 * A paid adapter is only constructed when `allow_paid` is on *and* the chosen
 * provider is the paid one; an unknown provider name falls back to the
 * free-first Piper adapter rather than to a hosted service.
 */
final class TtsProviderFactory
{
    /**
     * @param  array<string, mixed>  $config  The `ai.tts` block.
     */
    public static function fromConfig(array $config): TextToSpeechProvider
    {
        $selected = (string) ($config['provider'] ?? 'piper');
        $providers = is_array($config['providers'] ?? null) ? $config['providers'] : [];
        $block = is_array($providers[$selected] ?? null) ? $providers[$selected] : [];
        $block['allow_paid'] = (bool) ($config['allow_paid'] ?? false);

        return match ($selected) {
            'kokoro' => new KokoroTtsProvider($block),
            'openai' => new OpenAiTtsProvider($block),
            default => new PiperTtsProvider($block),
        };
    }
}
