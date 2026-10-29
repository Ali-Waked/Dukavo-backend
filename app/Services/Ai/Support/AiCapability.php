<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

/**
 * What a language-model provider is actually able to do.
 *
 * Providers are not interchangeable, and pretending they are is how an
 * application ends up depending on a feature its deployment cannot serve. Each
 * provider declares the capabilities it genuinely supports, and a use case
 * declares the capabilities it requires; the registry then matches the two and
 * refuses — loudly — rather than sending a request that will fail.
 *
 * `StructuredOutput` is deliberately coarse: it means "can return an object
 * matching a schema". A provider that enforces the schema natively and one that
 * merely returns JSON are both honest claimants, because the difference is
 * absorbed by the server-side validator that every completion passes through.
 * `NativeSchema` records the stronger guarantee for providers that have it.
 */
enum AiCapability: string
{
    /** Plain text completion. Every provider has this. */
    case TextCompletion = 'text_completion';

    /** Can return an object matching a requested JSON schema. */
    case StructuredOutput = 'structured_output';

    /** Enforces the schema itself rather than relying on instructions. */
    case NativeSchema = 'native_schema';

    /** Can call tools / functions. */
    case ToolCalls = 'tool_calls';

    /** Accepts image input. */
    case ImageInput = 'image_input';

    /** Accepts audio input and transcribes it. */
    case AudioTranscription = 'audio_transcription';

    /**
     * Whether this capability can stand in for another one.
     *
     * A provider that can only emit JSON still satisfies `StructuredOutput`;
     * the reverse is never true.
     */
    public function satisfies(self $required): bool
    {
        if ($this === $required) {
            return true;
        }

        return $required === self::StructuredOutput && $this === self::NativeSchema;
    }
}
