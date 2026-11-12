<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\Tts;

/**
 * Maps a synthesiser's output format onto the MIME type and extension Dukavo
 * stores and re-serves.
 *
 * Providers are allowed to return `opus`, `mp3`, `wav` or `ogg`; anything else
 * falls back to Ogg because every chat connector in the registry accepts an
 * Ogg voice note.
 */
final class AudioFormat
{
    /**
     * @return array{mime: string, extension: string}
     */
    public static function resolve(string $format): array
    {
        return match (strtolower(trim($format))) {
            'mp3', 'mpeg' => ['mime' => 'audio/mpeg', 'extension' => 'mp3'],
            'wav', 'wave' => ['mime' => 'audio/wav', 'extension' => 'wav'],
            'aac', 'm4a' => ['mime' => 'audio/aac', 'extension' => 'aac'],
            'opus' => ['mime' => 'audio/ogg', 'extension' => 'ogg'],
            default => ['mime' => 'audio/ogg', 'extension' => 'ogg'],
        };
    }

    /**
     * Read the format from a response `Content-Type`, so a provider that
     * ignores the requested format is still labelled correctly.
     */
    public static function fromContentType(?string $contentType): ?string
    {
        if (! is_string($contentType) || $contentType === '') {
            return null;
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));

        return match ($type) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/aac', 'audio/mp4' => 'aac',
            'audio/ogg', 'audio/opus' => 'ogg',
            default => null,
        };
    }
}
