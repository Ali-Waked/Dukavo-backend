<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\OpenAi;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Support\AiCapability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Adapter for any OpenAI-compatible `/chat/completions` endpoint.
 *
 * This exists to prove the abstraction is real: the feature layer never names a
 * provider, so swapping xAI for a self-hosted or third-party model is a config
 * change and nothing else. It is disabled unless a base URL and key are
 * configured.
 *
 * The interesting difference from the xAI adapter is structured output. Many
 * compatible endpoints only offer `json_object` — which asks for JSON but does
 * not enforce a schema — so this provider emulates schema conformance by
 * embedding the schema in the prompt and by validating the answer server-side
 * afterwards. That is why it advertises `StructuredOutput` but not
 * `NativeSchema`: the guarantee is real, it is just enforced by Dukavo rather
 * than by the provider.
 */
final class OpenAiCompatibleProvider implements AiProvider
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
        private readonly bool $nativeSchema = false,
    ) {}

    public function provider(): string
    {
        return 'openai';
    }

    /**
     * @return list<AiCapability>
     */
    public function capabilities(): array
    {
        $capabilities = [
            AiCapability::TextCompletion,
            AiCapability::StructuredOutput,
        ];

        if ($this->nativeSchema) {
            $capabilities[] = AiCapability::NativeSchema;
        }

        return $capabilities;
    }

    public function supports(AiCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== ''
            && trim($this->baseUrl) !== '';
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
            'messages' => $this->wireMessages($messages, $schema, $schemaName),
        ];

        if (isset($options['max_output_tokens'])) {
            $payload['max_completion_tokens'] = (int) $options['max_output_tokens'];
        }

        if (array_key_exists('temperature', $options) && $options['temperature'] !== null) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        if ($schema !== null) {
            $payload['response_format'] = $this->nativeSchema
                ? ['type' => 'json_schema', 'json_schema' => ['name' => $schemaName, 'schema' => $schema, 'strict' => true]]
                : ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * The chat-completions wire format wants `content` strings on every message
     * and no `safety_identifier`. When the provider cannot enforce the schema
     * itself, the schema travels in the system prompt and the model is asked
     * for JSON explicitly — the answer is then validated server-side, so a
     * non-conforming reply is rejected rather than trusted.
     *
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>|null  $schema
     * @return list<array{role: string, content: string}>
     */
    private function wireMessages(array $messages, ?array $schema, string $schemaName): array
    {
        $wire = array_map(static fn (AiMessage $message): array => $message->toArray(), $messages);

        if ($schema === null || $this->nativeSchema) {
            return $wire;
        }

        $instruction = sprintf(
            'Respond with a single JSON object that validates against this JSON Schema. '
            .'Output the JSON object only, with no prose and no code fences.%s',
            "\n".(string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if (isset($wire[0]) && $wire[0]['role'] === AiMessage::ROLE_SYSTEM) {
            $wire[0]['content'] .= "\n\n".$instruction;
        } else {
            array_unshift($wire, ['role' => AiMessage::ROLE_SYSTEM, 'content' => $instruction]);
        }

        return $wire;
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

        if ($status === 429 || $response->serverError()) {
            return AiUnavailable::provider('provider_status', ['provider_status' => $status]);
        }

        $providerCode = $response->json('error.code') ?? $response->json('code');

        return AiRequestFailed::providerRejected($status, is_string($providerCode) ? $providerCode : null);
    }

    private function completion(Response $response, bool $expectsStructure, int $latencyMs): AiCompletion
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw AiRequestFailed::invalidOutput('non-json body');
        }

        $choice = $data['choices'][0] ?? null;
        $content = is_array($choice) ? ($choice['message']['content'] ?? null) : null;

        if (! is_string($content)) {
            throw AiRequestFailed::invalidOutput('missing message content');
        }

        $text = trim($content);

        if ($text === '') {
            throw AiRequestFailed::invalidOutput('empty output');
        }

        $structured = null;

        if ($expectsStructure) {
            $structured = $this->decodeStructured($text);
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);
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
     * @return array<string, mixed>
     */
    private function decodeStructured(string $text): array
    {
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
