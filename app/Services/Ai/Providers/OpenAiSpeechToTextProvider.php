<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\SpeechToTextProvider;
use App\Services\Ai\Data\SpeechTranscription;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * An OpenAI-compatible multipart transcription adapter.
 *
 * It works with OpenAI, a proxy, or a self-hosted Whisper-compatible gateway.
 * The endpoint sees the audio bytes and nothing else: no tenant id, user id,
 * customer record or conversation is sent.
 */
final class OpenAiSpeechToTextProvider implements SpeechToTextProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function provider(): string
    {
        return (string) ($this->config['provider'] ?? 'openai');
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['enabled'] ?? true)
            && filled($this->config['api_key'] ?? null)
            && filled($this->config['base_url'] ?? null);
    }

    public function transcribe(
        string $contents,
        string $filename,
        string $mimeType,
        ?string $language = null,
    ): SpeechTranscription {
        if (! $this->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $started = microtime(true);
        $url = rtrim((string) $this->config['base_url'], '/').'/'.ltrim((string) ($this->config['path'] ?? '/audio/transcriptions'), '/');

        try {
            $response = Http::withToken((string) $this->config['api_key'])
                ->acceptJson()
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
                ->timeout((int) ($this->config['timeout'] ?? 120))
                ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
                ->post($url, array_filter([
                    'model' => (string) ($this->config['model'] ?? 'whisper-1'),
                    'response_format' => (string) ($this->config['response_format'] ?? 'verbose_json'),
                    'language' => $language ?: ($this->config['language'] ?? null),
                    'temperature' => 0,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException $exception) {
            throw AiUnavailable::provider('transcription_connection', [
                'message' => $exception->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            $code = data_get($response->json(), 'error.code');

            throw AiRequestFailed::providerRejected(
                $response->status(),
                is_string($code) ? $code : null,
            );
        }

        $payload = $response->json();
        $text = is_array($payload) ? trim((string) ($payload['text'] ?? '')) : '';

        if ($text === '') {
            throw AiRequestFailed::invalidOutput('the transcription was empty');
        }

        $duration = data_get($payload, 'duration');
        $confidence = data_get($payload, 'confidence');

        return new SpeechTranscription(
            text: $text,
            language: is_string(data_get($payload, 'language')) ? data_get($payload, 'language') : null,
            durationSeconds: is_numeric($duration) ? (float) $duration : null,
            confidence: is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null,
            model: (string) ($this->config['model'] ?? 'whisper-1'),
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            providerResponseId: is_string(data_get($payload, 'id')) ? data_get($payload, 'id') : null,
        );
    }
}
