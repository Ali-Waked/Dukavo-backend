<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\Tts;

use App\Services\Ai\Contracts\TextToSpeechProvider;
use App\Services\Ai\Data\SpeechSynthesis;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A self-hosted Piper engine behind a small HTTP wrapper.
 *
 * Piper is the free-first default: it runs on the installation's own hardware,
 * needs no key and costs nothing per call. The adapter speaks the common
 * `POST {text, voice}` JSON shape used by the Piper HTTP servers, so a
 * deployment can put any thin wrapper in front of the binary. When no base URL
 * is configured the provider reports itself unavailable rather than silently
 * returning an empty file.
 */
final class PiperTtsProvider implements TextToSpeechProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function provider(): string
    {
        return 'piper';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['enabled'] ?? true)
            && filled($this->config['base_url'] ?? null);
    }

    public function synthesize(string $text, ?string $voice = null, ?string $format = null): SpeechSynthesis
    {
        if (! $this->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $started = microtime(true);
        $url = rtrim((string) $this->config['base_url'], '/')
            .'/'.ltrim((string) ($this->config['path'] ?? '/api/tts'), '/');
        $requestedFormat = $format ?: (string) ($this->config['format'] ?? 'wav');

        try {
            $response = Http::accept('audio/*')
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->post($url, array_filter([
                    'text' => $text,
                    'voice' => $voice ?: ($this->config['voice'] ?? null),
                    'format' => $requestedFormat,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException $exception) {
            throw AiUnavailable::provider('tts_connection', [
                'message' => $exception->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            throw AiRequestFailed::providerRejected($response->status());
        }

        $contents = $response->body();

        if (! is_string($contents) || trim($contents) === '') {
            throw AiRequestFailed::invalidOutput('the synthesiser returned no audio');
        }

        $resolved = AudioFormat::fromContentType($response->header('Content-Type')) ?? $requestedFormat;
        $audio = AudioFormat::resolve($resolved);

        return new SpeechSynthesis(
            contents: $contents,
            mimeType: $audio['mime'],
            filename: 'reply-'.Str::uuid()->toString().'.'.$audio['extension'],
            format: $audio['extension'],
            voice: (string) ($voice ?: ($this->config['voice'] ?? '')),
            model: (string) ($this->config['model'] ?? 'piper'),
            latencyMs: (int) round((microtime(true) - $started) * 1000),
        );
    }
}
