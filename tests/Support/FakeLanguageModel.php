<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use Throwable;

/**
 * A language model that never touches the network.
 *
 * Bound in place of the real client in feature tests, so the endpoint contract
 * (auth, permissions, tenant isolation, limits, response shape) can be asserted
 * without spending credits or depending on a provider. It can be told to return
 * a canned completion or to raise a specific domain exception.
 */
final class FakeLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly ?AiCompletion $completion = null,
        private readonly ?Throwable $throw = null,
    ) {}

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
        $this->guard();

        return $this->completion ?? new AiCompletion(text: '{}');
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $options
     */
    public function structured(array $messages, array $schema, string $schemaName, array $options = []): AiCompletion
    {
        $this->guard();

        return $this->completion ?? new AiCompletion(text: '{}', structured: []);
    }

    private function guard(): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}
