<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\Anthropic;

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
 * Anthropic Claude adapter over the official Messages API.
 *
 * Structured output is requested by wrapping the JSON Schema in a single
 * forced tool definition (`tool_choice: {"type": "tool"}`), which is how
 * Anthropic documents schema-constrained output. The key travels as the
 * `x-api-key` header with the required `anthropic-version` header.
 */
final class AnthropicProvider implements AiProvider
{
    private const VERSION = '2023-06-01';

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
        return 'anthropic';
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
        return $this->send($messages, $options, $schema, $schemaName);
    }

    /**
     * A cheap credential check for the management UI: a one-token message.
     *
     * Never returns the key; only whether the provider accepted it.
     */
    public function testConnection(): array
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client()
                ->post(rtrim($this->baseUrl, '/').'/messages', [
                    'model' => $this->defaultModel,
                    'max_tokens' => 8,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 0, 'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000)];
        }
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array{max_output_tokens?: int, temperature?: float|null, model?: string|null}  $options
     * @param  array<string, mixed>|null  $schema
     */
    private function send(array $messages, array $options, ?array $schema, string $schemaName = ''): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        [$system, $turns] = $this->contents($messages);

        $payload = [
            'model' => (string) ($options['model'] ?? $this->defaultModel),
            'max_tokens' => max(16, (int) ($options['max_output_tokens'] ?? 1024)),
            'messages' => $turns,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if (array_key_exists('temperature', $options) && $options['temperature'] !== null) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        if ($schema !== null) {
            $payload['tools'] = [[
                'name' => $schemaName !== '' ? $schemaName : 'respond',
                'description' => 'Return the answer as a JSON object matching the schema.',
                'input_schema' => $schema,
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => $schemaName !== '' ? $schemaName : 'respond'];
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->client()
                ->retry($this->backoffDelays(), 0, $this->shouldRetry(...))
                ->post(rtrim($this->baseUrl, '/').'/messages', $payload);
        } catch (ConnectionException $exception) {
            throw AiUnavailable::provider('connection', ['provider' => 'anthropic']);
        } catch (RequestException $exception) {
            throw ProviderErrorMapper::map($exception->response, 'anthropic');
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw ProviderErrorMapper::map($response, 'anthropic');
        }

        return $this->completion($response, $schema !== null, (string) $payload['model'], $latencyMs);
    }

    /**
     * The Messages API keeps the system prompt outside the turn list, so it
     * cannot be overwritten by anything a turn says.
     *
     * @param  list<AiMessage>  $messages
     * @return array{0: string, 1: list<array{role: string, content: string}>}
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

            $turns[] = ['role' => $message->role, 'content' => $message->content];
        }

        return [$system, $turns];
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key' => (string) $this->apiKey,
            'anthropic-version' => self::VERSION,
        ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->transport['connect_timeout'] ?? 5))
            ->timeout((int) ($this->transport['timeout'] ?? 60));
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

        $stop = is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : '';

        if ($stop === 'refusal') {
            throw AiRequestFailed::contentPolicy(200, 'refusal');
        }

        $text = '';
        $structured = null;

        foreach ((array) ($data['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }

            if (($block['type'] ?? '') === 'tool_use' && $expectsStructure && is_array($block['input'] ?? null)) {
                $structured = $block['input'];
            }
        }

        $text = trim($text);

        if ($text === '' && $structured === null) {
            throw AiRequestFailed::invalidOutput('empty output');
        }

        if ($expectsStructure && $structured === null && $text !== '') {
            $decoded = json_decode($text, true);

            if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw AiRequestFailed::invalidOutput('structured output is not valid json');
            }

            $structured = $decoded;
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        return new AiCompletion(
            text: $text,
            structured: $structured,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $inputTokens + $outputTokens,
            latencyMs: $latencyMs,
            providerResponseId: is_string($data['id'] ?? null) ? $data['id'] : null,
        );
    }
}
