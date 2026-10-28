<?php

declare(strict_types=1);

namespace App\Services\Ai\Grok;

use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * xAI/Grok implementation of {@see LanguageModel} over the Responses API.
 *
 * Why the Responses API: it is xAI's supported, non-legacy text endpoint. The
 * client always sends `store: false`, so neither the prompt nor the answer is
 * retained on xAI's side — the default is to keep them for 30 days, which is not
 * acceptable for customer content.
 *
 * The client knows nothing about Dukavo's domain. It validates the transport,
 * bounds retries for genuinely transient failures, and hands back a value object;
 * it never lets a raw provider error, header or stack trace reach a caller.
 */
final class GrokClient implements LanguageModel
{
    /**
     * @param  array{connect_timeout?: int, timeout?: int, retry_times?: int, retry_base_ms?: int, retry_max_ms?: int, max_prompt_chars?: int}  $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $path,
        private readonly string $defaultModel,
        private readonly array $transport = [],
    ) {}

    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function complete(array $messages, array $options = []): AiCompletion
    {
        return $this->send($messages, $options, null, '');
    }

    public function structured(array $messages, array $schema, string $schemaName, array $options = []): AiCompletion
    {
        return $this->send($messages, $options, $schema, $schemaName);
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array{max_output_tokens?: int, temperature?: float|null, safety_identifier?: string|null, model?: string|null}  $options
     * @param  array<string, mixed>|null  $schema
     */
    private function send(array $messages, array $options, ?array $schema, string $schemaName): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        if ($messages === []) {
            throw AiRequestFailed::invalidOutput('empty prompt');
        }

        $this->assertWithinPromptLimit($messages);

        $payload = $this->payload($messages, $options, $schema, $schemaName);

        $startedAt = hrtime(true);

        try {
            $response = $this->client()
                ->retry($this->backoffDelays(), 0, $this->shouldRetry(...))
                ->post($this->url(), $payload);
        } catch (ConnectionException $exception) {
            throw AiUnavailable::provider('connection', ['message' => $exception->getMessage()]);
        } catch (RequestException $exception) {
            throw $this->mapFailure($exception->response);
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw $this->mapFailure($response);
        }

        return $this->completion($response, $schema !== null, $latencyMs);
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array{max_output_tokens?: int, temperature?: float|null, safety_identifier?: string|null, model?: string|null}  $options
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    private function payload(array $messages, array $options, ?array $schema, string $schemaName): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->defaultModel,
            'input' => array_map(static fn (AiMessage $message): array => $message->toArray(), $messages),
            'store' => false,
        ];

        if (isset($options['max_output_tokens'])) {
            $payload['max_output_tokens'] = (int) $options['max_output_tokens'];
        }

        if (array_key_exists('temperature', $options) && $options['temperature'] !== null) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        // A stable, non-reversible identifier lets the provider attribute abuse
        // without ever receiving a name or an email address.
        if (! empty($options['safety_identifier'])) {
            $payload['safety_identifier'] = (string) $options['safety_identifier'];
        }

        if ($schema !== null) {
            $payload['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'schema' => $schema,
                    'strict' => true,
                ],
            ];
        }

        return $payload;
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->transport['connect_timeout'] ?? 5))
            ->timeout((int) ($this->transport['timeout'] ?? 60));
    }

    private function url(): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($this->path, '/');
    }

    /**
     * Exponential backoff, one delay per retry, capped so a long outage cannot
     * make a request hang for minutes.
     *
     * @return list<int>
     */
    private function backoffDelays(): array
    {
        $retries = max(0, (int) ($this->transport['retry_times'] ?? 0));
        $base = max(0, (int) ($this->transport['retry_base_ms'] ?? 300));
        $max = max($base, (int) ($this->transport['retry_max_ms'] ?? 4000));

        $delays = [];
        $delay = $base;

        for ($i = 0; $i < $retries; $i++) {
            $delays[] = min($delay, $max);
            $delay *= 2;
        }

        return $delays;
    }

    /**
     * Only genuine transient failures are retried. A 4xx (bad request, auth,
     * schema rejected) is final: repeating it would waste quota and change
     * nothing.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return $exception->response->serverError() || $exception->response->status() === 429;
        }

        return false;
    }

    private function mapFailure(Response $response): AiUnavailable|AiRequestFailed
    {
        $status = $response->status();

        // A rate limit or a provider-side fault is expected and recoverable.
        if ($status === 429 || $response->serverError()) {
            return AiUnavailable::provider('provider_status', ['provider_status' => $status]);
        }

        // Everything else is a request we should not repeat verbatim.
        $providerCode = $response->json('error.code') ?? $response->json('code');

        return AiRequestFailed::providerRejected($status, is_string($providerCode) ? $providerCode : null);
    }

    private function completion(Response $response, bool $expectsStructure, int $latencyMs): AiCompletion
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw AiRequestFailed::invalidOutput('non-json body');
        }

        if (($data['status'] ?? null) === 'incomplete') {
            throw AiRequestFailed::invalidOutput('response incomplete');
        }

        $text = $this->extractText($data['output'] ?? []);

        $structured = null;

        if ($expectsStructure) {
            $structured = $this->decodeStructured($text);
        } elseif ($text === '') {
            // A 200 with no usable text is a failed attempt, not an empty answer.
            if (isset($data['error'])) {
                throw AiRequestFailed::invalidOutput('provider error object');
            }

            throw AiRequestFailed::invalidOutput('empty output');
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $inputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

        return new AiCompletion(
            text: $text,
            structured: $structured,
            model: is_string($data['model'] ?? null) ? $data['model'] : $this->defaultModel,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            latencyMs: $latencyMs,
            providerResponseId: is_string($data['id'] ?? null) ? $data['id'] : null,
        );
    }

    /**
     * Concatenate every `output_text` part of every `message` item. Reasoning
     * items and tool items are ignored: only assistant prose is a valid answer.
     */
    private function extractText(mixed $output): string
    {
        if (! is_array($output)) {
            return '';
        }

        $text = '';

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        return trim($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStructured(string $text): array
    {
        if ($text === '') {
            throw AiRequestFailed::invalidOutput('empty structured output');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw AiRequestFailed::invalidOutput('structured output is not valid json');
        }

        return $decoded;
    }

    /**
     * @param  list<AiMessage>  $messages
     */
    private function assertWithinPromptLimit(array $messages): void
    {
        $limit = (int) ($this->transport['max_prompt_chars'] ?? 24000);
        $length = 0;

        foreach ($messages as $message) {
            $length += mb_strlen($message->content);
        }

        if ($length > $limit) {
            throw AiRequestFailed::invalidOutput('prompt exceeds the configured size limit');
        }
    }
}
