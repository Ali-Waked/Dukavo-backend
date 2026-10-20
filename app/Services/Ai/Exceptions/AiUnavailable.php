<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The provider could not be used, but this is a recoverable, expected state.
 *
 * Covers a missing key, a disabled feature, an unreachable host, a timeout, a
 * quota refusal and a 5xx from the provider. None of these is a Dukavo defect,
 * so the request is answered with a clear 503 and the surrounding product keeps
 * working; the reason travels to the log, never to the client.
 */
class AiUnavailable extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'ai_unavailable',
        private readonly array $context = [],
        private readonly int $status = 503,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('AI assistance is not configured on this installation.', 'ai_not_configured');
    }

    public static function disabled(string $feature): self
    {
        return new self('This AI feature is currently turned off.', 'ai_feature_disabled', ['feature' => $feature]);
    }

    public static function rateLimited(string $scope): self
    {
        return new self(
            'You have reached today\'s AI usage limit. Please try again tomorrow.',
            'ai_rate_limited',
            ['scope' => $scope],
            429,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function provider(string $reason, array $context = []): self
    {
        return new self('AI assistance is temporarily unavailable. Please try again shortly.', 'ai_unavailable', ['reason' => $reason] + $context);
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
        ], $this->status);
    }
}
