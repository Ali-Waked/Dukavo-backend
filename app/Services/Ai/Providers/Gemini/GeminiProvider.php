<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\Gemini;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Providers\ProviderErrorMapper;
use App\Services\Ai\Support\AiCapability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini adapter over the official `generateContent` REST surface.
 *
 * Structured output is requested through Gemini's `responseSchema` /
 * `responseMimeType` pair, which the API enforces natively on supported
 * models. The key travels as the `x-goog-api-key` header, never in the URL.
 */
final class GeminiProvider implements AiProvider
{
    /**
     * @param  array{connect_timeout?: int, timeout?: int, retry_times?: int, retry_base_ms?: int, retry_max_ms?: int, max_prompt_chars?: int}  $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $defaultModel,
        private readonly array $transport = [],
    ) {}

    public function provider(): string
    {
        return 'gemini';
    }

    /**
     * @return list<AiCapability>
     */
    public function capabilities(): array
    {
        return [
            AiCapability::TextCompletion,
            AiCapability::StructuredOutput,
            AiCapability::NativeSchema,
            AiCapability::ToolCalls,
            AiCapability::ImageInput,
        ];
    }

    public function supports(AiCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== ''
            && trim($this->baseUrl) !== ''
            && trim($this->defaultModel) !== '';
    }

    public function complete(array $messages, array $options = []): AiCompletion
    {
        return $this->send($messages, $options, null);
    }

    public function structured(array $messages, array $schema, string $schemaName, array $options = []): AiCompletion
    {
        return $this->send($messages, $options, $schema);
    }

    /**
     * A cheap credential check for the management UI: `GET /models`.
     *
     * Never returns the key; only whether the provider accepted it.
     */
    public function testConnection(): array
    {
        $startedAt = hrtime(true);

        $response = $this->client()
            ->get(rtrim($this->baseUrl, '/').'/models');

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array{max_output_tokens?: int, temperature?: float|null, model?: string|null}  $options
     * @param  array<string, mixed>|null  $schema
     */
    private function send(array $messages, array $options, ?array $schema): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $model = (string) ($options['model'] ?? $this->defaultModel);
        [$system, $turns] = $this->contents($messages);

        $payload = [
            'contents' => $turns,
            'generationConfig' => $this->generationConfig($options, $schema),
        ];

        if ($system !== '') {
            // The trusted instruction sits above the turns, in Gemini's own
            // system slot, so user content can never masquerade as it.
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->client()
                ->retry($this->backoffDelays(), 0, $this->shouldRetry(...))
                ->post($this->url($model), $payload);
        } catch (ConnectionException $exception) {
            throw AiUnavailable::provider('connection', ['provider' => 'gemini']);
        } catch (RequestException $exception) {
            throw ProviderErrorMapper::map($exception->response, 'gemini');
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw ProviderErrorMapper::map($response, 'gemini');
        }

        return $this->completion($response, $schema !== null, $model, $latencyMs);
    }

    /**
     * Gemini's wire format: one `contents` list with `role`d parts, and the
     * system prompt lifted out of it into Gemini's own system slot.
     *
     * @param  list<AiMessage>  $messages
     * @return array{0: string, 1: list<array{role: string, parts: list<array{text: string}>}>}
     */
    private function contents(array $messages): array
    {
        $system = '';
        $turns = [];

        foreach ($messages as $message) {
            if ($message->role === AiMessage::ROLE_SYSTEM) {
                $system .= ($system === '' ? '' : "\n\n").$message->content;

                continue;
            }

            $turns[] = [
                'role' => $message->role === AiMessage::ROLE_ASSISTANT ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }

        return [$system, $turns];
    }

    /**
     * @param  array{max_output_tokens?: int, temperature?: float|null}  $options
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    private function generationConfig(array $options, ?array $schema): array
    {
        $config = [];

        if (isset($options['max_output_tokens'])) {
            $config['maxOutputTokens'] = (int) $options['max_output_tokens'];
        }

        if (array_key_exists('temperature', $options) && $options['temperature'] !== null) {
            $config['temperature'] = (float) $options['temperature'];
        }

        if ($schema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseSchema'] = $this->toGeminiSchema($schema);
        }

        return $config;
    }

    /**
     * Convert a JSON Schema subset into Gemini's schema dialect.
     *
     * Gemini understands `type`, `properties`, `required`, `items`,
     * `description` and `enum`. Anything else is dropped rather than sent and
     * rejected — the server-side validator remains the guarantee.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        $type = is_string($schema['type'] ?? null) ? $schema['type'] : 'object';

        $converted = ['type' => strtoupper(substr($type, 0, 1)).substr($type, 1)];

        if (is_string($schema['description'] ?? null)) {
            $converted['description'] = $schema['description'];
        }

        if (is_array($schema['enum'] ?? null)) {
            $converted['enum'] = array_values($schema['enum']);
        }

        if (is_array($schema['properties'] ?? null)) {
            $properties = [];

            foreach ($schema['properties'] as $name => $property) {
                $properties[$name] = is_array($property) ? $this->toGeminiSchema($property) : ['type' => 'String'];
            }

            $converted['properties'] = $properties;
        }

        if (is_array($schema['required'] ?? null)) {
            $converted['required'] = array_values(array_filter($schema['required'], 'is_string'));
        }

        if (is_array($schema['items'] ?? null)) {
            $converted['items'] = $this->toGeminiSchema($schema['items']);
        }

        return $converted;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => (string) $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->transport['connect_timeout'] ?? 5))
            ->timeout((int) ($this->transport['timeout'] ?? 60));
    }

    private function url(string $model): string
    {
        $base = rtrim($this->baseUrl, '/');
        $path = '/models/'.rawurlencode($model).':generateContent';

        return $base.$path;
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

    private function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return $exception->response->serverError() || $exception->response->status() === 429;
        }

        return false;
    }

    private function completion(Response $response, bool $expectsStructure, string $model, int $latencyMs): AiCompletion
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw AiRequestFailed::invalidOutput('non-json body');
        }

        // A refusal arrives as `promptFeedback.blockReason` with no content.
        $blockReason = $data['promptFeedback']['blockReason'] ?? null;

        if (is_string($blockReason) && $blockReason !== '') {
            throw AiRequestFailed::contentPolicy(200, $blockReason);
        }

        $candidates = $data['candidates'] ?? [];
        $first = is_array($candidates[0] ?? null) ? $candidates[0] : null;

        if ($first === null) {
            throw AiRequestFailed::invalidOutput('no candidates');
        }

        $finish = is_string($first['finishReason'] ?? null) ? $first['finishReason'] : '';

        if ($finish === 'SAFETY') {
            throw AiRequestFailed::contentPolicy(200, 'SAFETY');
        }

        $text = trim((string) ($first['content']['parts'][0]['text'] ?? ''));

        if ($text === '') {
            throw AiRequestFailed::invalidOutput('empty output');
        }

        $structured = null;

        if ($expectsStructure) {
            $decoded = json_decode($text, true);

            if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw AiRequestFailed::invalidOutput('structured output is not valid json');
            }

            $structured = $decoded;
        }

        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];

        $inputTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($usage['candidatesTokenCount'] ?? 0);

        return new AiCompletion(
            text: $text,
            structured: $structured,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: (int) ($usage['totalTokenCount'] ?? ($inputTokens + $outputTokens)),
            latencyMs: $latencyMs,
            providerResponseId: is_string($data['responseId'] ?? null) ? $data['responseId'] : null,
        );
    }
}
