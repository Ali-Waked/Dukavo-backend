<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use App\Enums\AiErrorCategory;
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

    public static function rateLimited(string $scope, string $unit = 'requests'): self
    {
        return new self(
            'You have reached today\'s AI usage limit. Please try again tomorrow.',
            'ai_rate_limited',
            ['scope' => $scope, 'unit' => $unit],
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

    /**
     * No configured provider can serve the request.
     *
     * This is a deployment fact, not a transient fault: the requested capability
     * (native schema enforcement, audio, tool calls) is not offered by the
     * provider this installation uses.
     *
     * @param  list<string>  $capabilities
     */
    public static function unsupportedCapability(array $capabilities): self
    {
        return new self(
            'AI assistance is not available for this request on this installation.',
            'ai_unsupported_capability',
            ['capabilities' => $capabilities],
        );
    }

    /**
     * The credential was refused by the provider.
     *
     * The engine marks the provider's configuration unhealthy and notifies
     * the platform owner, then serves the request from another provider when
     * one is configured.
     */
    public static function authentication(string $provider, int $status): self
    {
        return new self(
            'AI assistance is temporarily unavailable. Please try again shortly.',
            'ai_provider_authentication',
            ['reason' => 'authentication', 'provider' => $provider, 'provider_status' => $status],
        );
    }

    /**
     * The provider rate limited the call.
     *
     * `retryAfterSeconds` carries the provider's own Retry-After when it sent
     * one, so the engine can honour it instead of hammering the provider.
     */
    public static function rateLimitedByProvider(string $provider, ?int $retryAfterSeconds = null): self
    {
        return new self(
            'AI assistance is temporarily unavailable. Please try again shortly.',
            'ai_provider_rate_limited',
            ['reason' => 'rate_limited', 'provider' => $provider, 'retry_after_seconds' => $retryAfterSeconds],
        );
    }

    /**
     * The provider answered with a server error.
     */
    public static function providerStatus(string $provider, int $status): self
    {
        return new self(
            'AI assistance is temporarily unavailable. Please try again shortly.',
            'ai_unavailable',
            ['reason' => 'provider_status', 'provider' => $provider, 'provider_status' => $status],
        );
    }

    /**
     * The call did not complete within the configured timeout.
     */
    public static function timedOut(string $provider, int $timeoutSeconds): self
    {
        return new self(
            'AI assistance is temporarily unavailable. Please try again shortly.',
            'ai_provider_timeout',
            ['reason' => 'timeout', 'provider' => $provider, 'timeout_seconds' => $timeoutSeconds],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The normalized failure category for this exception.
     *
     * The execution engine decides retries and failover on this, never on the
     * raw message. Derived from the code so an adapter cannot misreport it.
     */
    public function errorCategory(): AiErrorCategory
    {
        return match ($this->errorCode) {
            'ai_not_configured' => AiErrorCategory::Connection,
            'ai_feature_disabled' => AiErrorCategory::Validation,
            'ai_rate_limited' => AiErrorCategory::RateLimit,
            'ai_unsupported_capability' => AiErrorCategory::Validation,
            'ai_provider_authentication' => AiErrorCategory::Authentication,
            'ai_provider_rate_limited' => AiErrorCategory::RateLimit,
            'ai_provider_timeout' => AiErrorCategory::Timeout,
            default => match ($this->context['reason'] ?? null) {
                'connection' => AiErrorCategory::Connection,
                'timeout' => AiErrorCategory::Timeout,
                'provider_status' => AiErrorCategory::ServerError,
                'rate_limited' => AiErrorCategory::RateLimit,
                'authentication' => AiErrorCategory::Authentication,
                default => AiErrorCategory::Unknown,
            },
        };
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
