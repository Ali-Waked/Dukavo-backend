<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;

/**
 * A pluggable language-model backend.
 *
 * The application depends only on this contract, so the provider (currently
 * xAI/Grok), its endpoint, and its wire format never leak into controllers or
 * feature services, and tests can substitute a fake without touching the network.
 *
 * Implementations MUST be safe with untrusted input, honour the configured
 * timeouts/retries, and raise {@see AiUnavailable}
 * or {@see AiRequestFailed} rather than returning
 * partial data.
 */
interface LanguageModel
{
    /**
     * Whether a usable credential is present. A false answer means callers
     * should degrade gracefully instead of attempting a request.
     */
    public function isConfigured(): bool;

    /**
     * Free-form text completion.
     *
     * @param  list<AiMessage>  $messages
     * @param  array{max_output_tokens?: int, temperature?: float|null, safety_identifier?: string|null}  $options
     */
    public function complete(array $messages, array $options = []): AiCompletion;

    /**
     * Completion constrained to a JSON Schema. The decoded object is returned on
     * {@see AiCompletion::$structured}; a response that does not decode is a
     * recoverable failure, never trusted data.
     *
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $schema  A JSON Schema (Draft 2020-12 subset).
     * @param  array{max_output_tokens?: int, temperature?: float|null, safety_identifier?: string|null}  $options
     */
    public function structured(array $messages, array $schema, string $schemaName, array $options = []): AiCompletion;
}
