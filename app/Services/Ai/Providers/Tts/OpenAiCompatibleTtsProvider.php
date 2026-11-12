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
 * Shared adapter for the OpenAI `/audio/speech` shape.
 *
 * Kokoro and OpenAI both accept the same JSON body, so one implementation
 * serves the free self-hosted engine and the paid hosted one. The difference is
 * only whether a key is required and whether a call is billed; a deployment
 * must opt in before the paid subclass can ever be selected.
 */
abstract class OpenAiCompatibleTtsProvider implements TextToSpeechProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected readonly array $config) {}

    abstract public function isPaid(): bool;

    /**
     * Whether the engine refuses to run without an API key.
     */
    abstract protected function requiresKey(): bool;

    public function isConfigured(): bool
    {
        if (! (bool) ($this->config['enabled'] ?? true) || blank($this->config['base_url'] ?? null)) {
            return false;
        }

        if ($this->requiresKey() && blank($this->config['api_key'] ?? null)) {
            return false;
        }

        return true;
    }

    public function synthesize(string $text, ?string $voice = null, ?string $format = null): SpeechSynthesis
    {
        if (! $this->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $started = microtime(true);
        $url = rtrim((string) $this->config['base_url'], '/')
            .'/'.ltrim((string) ($this->config['path'] ?? '/audio/speech'), '/');
        $requestedFormat = $format ?: (string) ($this->config['format'] ?? 'opus');

        $request = Http::accept('audio/*')
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->timeout((int) ($this->config['timeout'] ?? 60));

        if (filled($this->config['api_key'] ?? null)) {
            $request = $request->withToken((string) $this->config['api_key']);
        }

        try {
            $response = $request->post($url, array_filter([
                'model' => (string) ($this->config['model'] ?? 'tts-1'),
                'input' => $text,
                'voice' => $voice ?: ($this->config['voice'] ?? 'alloy'),
                'response_format' => $requestedFormat,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException $exception) {
            throw AiUnavailable::provider('tts_connection', [
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
            model: (string) ($this->config['model'] ?? 'tts-1'),
            latencyMs: (int) round((microtime(true) - $started) * 1000),
            providerResponseId: is_string(data_get($response->json(), 'id')) ? data_get($response->json(), 'id') : null,
        );
    }
}
