<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Support\AiCapability;

/**
 * A fake provider that also records what the feature sent it.
 *
 * Prompt-shape tests need to prove that untrusted content is delimited and that
 * only authorised records reached the model. The other fake returns canned
 * answers without retaining the prompt, so this one keeps every call.
 */
final class RecordingLanguageModel implements AiProvider
{
    /**
     * @var list<array{messages: list<AiMessage>, schema: array<string, mixed>|null, schema_name: string|null}>
     */
    public array $calls = [];

    public function __construct(private readonly AiCompletion $completion) {}

    public function provider(): string
    {
        return 'fake';
    }

    /**
     * @return list<AiCapability>
     */
    public function capabilities(): array
    {
        return [AiCapability::TextCompletion, AiCapability::StructuredOutput, AiCapability::NativeSchema];
    }

    public function supports(AiCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, array $options = []): AiCompletion
    {
        $this->calls[] = ['messages' => $messages, 'schema' => null, 'schema_name' => null];

        return $this->completion;
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $options
     */
    public function structured(array $messages, array $schema, string $schemaName, array $options = []): AiCompletion
    {
        $this->calls[] = ['messages' => $messages, 'schema' => $schema, 'schema_name' => $schemaName];

        return $this->completion;
    }

    public function lastSystemPrompt(): string
    {
        return $this->promptFor('system');
    }

    public function lastUserPrompt(): string
    {
        return $this->promptFor('user');
    }

    private function promptFor(string $role): string
    {
        $call = $this->calls === [] ? null : $this->calls[array_key_last($this->calls)];

        if ($call === null) {
            return '';
        }

        foreach ($call['messages'] as $message) {
            if ($message->role === $role) {
                return $message->content;
            }
        }

        return '';
    }
}
