<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The provider answered, but the request or the response was not usable.
 *
 * This is the "recoverable failure" half of the contract: a rejected schema, an
 * authentication error, a truncated response or model output that does not
 * match the requested structure. The client is told the attempt failed and is
 * invited to retry rather than being handed partial, untrusted data.
 */
class AiRequestFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'ai_request_failed',
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function providerRejected(int $status, ?string $providerCode = null): self
    {
        return new self(
            'The AI request was rejected. Please try again or rephrase your request.',
            'ai_request_rejected',
            ['provider_status' => $status, 'provider_code' => $providerCode],
        );
    }

    public static function invalidOutput(string $reason): self
    {
        return new self(
            'The AI returned a response Dukavo could not use. Please try again.',
            'ai_invalid_response',
            ['reason' => $reason],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], 502);
    }
}
