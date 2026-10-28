<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Providers\Xai\XaiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The xAI/Grok transport, isolated from the network with `Http::fake`.
 *
 * These tests pin the contract the rest of the application relies on: only the
 * Responses API is used, `store` is always false, structured output is decoded,
 * transient failures are retried and terminal ones are mapped to domain
 * exceptions — never surfaced as a raw provider payload.
 */
class XaiProviderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $transport
     */
    private function client(array $transport = []): XaiProvider
    {
        return new XaiProvider(
            baseUrl: 'https://api.x.ai/v1',
            apiKey: 'test-key',
            path: '/responses',
            defaultModel: 'grok-4.7',
            transport: array_merge([
                'connect_timeout' => 1,
                'timeout' => 5,
                'retry_times' => 0,
                'retry_base_ms' => 0,
                'retry_max_ms' => 0,
                'max_prompt_chars' => 1000,
            ], $transport),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function successBody(string $text, array $overrides = []): array
    {
        return array_merge([
            'id' => 'resp_123',
            'model' => 'grok-4.7',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ], $overrides);
    }

    public function test_it_is_not_configured_without_a_key(): void
    {
        $client = new XaiProvider('https://api.x.ai/v1', null, '/responses', 'grok-4.7');

        $this->assertFalse($client->isConfigured());

        $this->expectException(AiUnavailable::class);
        $client->complete([AiMessage::user('hi')]);
    }

    public function test_it_parses_a_text_completion_and_records_usage(): void
    {
        Http::fake(['api.x.ai/*' => Http::response($this->successBody('Hello there'))]);

        $completion = $this->client()->complete([AiMessage::system('s'), AiMessage::user('hi')]);

        $this->assertSame('Hello there', $completion->text);
        $this->assertSame(10, $completion->inputTokens);
        $this->assertSame(5, $completion->outputTokens);
        $this->assertSame(15, $completion->totalTokens);
        $this->assertSame('resp_123', $completion->providerResponseId);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.x.ai/v1/responses'
                && $body['store'] === false
                && $body['model'] === 'grok-4.7'
                && $body['input'][0]['role'] === 'system';
        });
    }

    public function test_it_parses_a_structured_completion(): void
    {
        Http::fake(['api.x.ai/*' => Http::response($this->successBody('{"reply":"hi","tone":"friendly","suggestions":[]}'))]);

        $completion = $this->client()->structured(
            [AiMessage::user('hi')],
            ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false],
            'reply_draft',
        );

        $this->assertSame(['reply' => 'hi', 'tone' => 'friendly', 'suggestions' => []], $completion->structured);

        Http::assertSent(fn ($request): bool => ($request->data()['text']['format']['type'] ?? null) === 'json_schema');
    }

    public function test_invalid_structured_output_is_a_recoverable_failure(): void
    {
        Http::fake(['api.x.ai/*' => Http::response($this->successBody('not json'))]);

        $this->expectException(AiRequestFailed::class);
        $this->client()->structured([AiMessage::user('hi')], ['type' => 'object'], 'x');
    }

    public function test_an_incomplete_response_is_rejected(): void
    {
        Http::fake(['api.x.ai/*' => Http::response($this->successBody('partial', ['status' => 'incomplete']))]);

        $this->expectException(AiRequestFailed::class);
        $this->client()->complete([AiMessage::user('hi')]);
    }

    public function test_a_rate_limit_becomes_unavailable(): void
    {
        Http::fake(['api.x.ai/*' => Http::response(['error' => ['code' => 'rate_limit']], 429)]);

        $this->expectException(AiUnavailable::class);
        $this->client()->complete([AiMessage::user('hi')]);
    }

    public function test_a_bad_request_becomes_a_request_failure(): void
    {
        Http::fake(['api.x.ai/*' => Http::response(['error' => ['code' => 'invalid_request']], 400)]);

        try {
            $this->client()->complete([AiMessage::user('hi')]);
            $this->fail('Expected an AiRequestFailed exception.');
        } catch (AiRequestFailed $exception) {
            $this->assertSame('ai_request_rejected', $exception->errorCode());
        }
    }

    public function test_it_retries_transient_server_errors_then_succeeds(): void
    {
        Http::fake(['api.x.ai/*' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push($this->successBody('Recovered'), 200)]);

        $completion = $this->client(['retry_times' => 1])->complete([AiMessage::user('hi')]);

        $this->assertSame('Recovered', $completion->text);
        Http::assertSentCount(2);
    }

    public function test_a_connection_failure_becomes_unavailable(): void
    {
        Http::fake(['api.x.ai/*' => fn () => throw new ConnectionException('down')]);

        $this->expectException(AiUnavailable::class);
        $this->client()->complete([AiMessage::user('hi')]);
    }

    public function test_an_oversized_prompt_is_rejected_before_the_network(): void
    {
        Http::fake();

        try {
            $this->client(['max_prompt_chars' => 5])->complete([AiMessage::user('way too long')]);
            $this->fail('Expected an AiRequestFailed exception.');
        } catch (AiRequestFailed $exception) {
            $this->assertSame('ai_invalid_response', $exception->errorCode());
        }

        Http::assertNothingSent();
    }
}
