<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a provider call failed, decided by the execution engine.
 *
 * The category drives the failure policy — which failures may be retried,
 * which may move to another provider and which must never be worked around.
 * It is stored on the usage row and the activity log so operators see the
 * cause rather than a bare HTTP status.
 */
enum AiErrorCategory: string
{
    /** The request was rejected as invalid. Never retried, never failed over. */
    case Validation = 'validation';

    /** The credential was refused. Marks the provider unhealthy and fails over. */
    case Authentication = 'authentication';

    /** Rate limited. Honours Retry-After, may fail over. */
    case RateLimit = 'rate_limit';

    /** The provider failed on its side. Bounded retry, then failover. */
    case ServerError = 'server_error';

    /** The call took too long. Bounded retry, then failover. */
    case Timeout = 'timeout';

    /** The provider refused the content on policy grounds. Never failed over. */
    case ContentPolicy = 'content_policy';

    /** The provider could not be reached. Bounded retry, then failover. */
    case Connection = 'connection';

    /** The answer was unusable (bad JSON, schema mismatch). One repair, no failover. */
    case InvalidOutput = 'invalid_output';

    /** Anything unclassified. Treated conservatively: single retry, no failover. */
    case Unknown = 'unknown';

    /**
     * Whether a failure of this category may be served by another provider.
     *
     * Content-policy refusals must not be routed around: the refusal is the
     * provider making a safety decision, and asking a second vendor for the
     * same thing is exactly the bypass that must not happen.
     */
    public function mayFailOver(): bool
    {
        return match ($this) {
            self::ContentPolicy,
            self::Validation,
            self::InvalidOutput => false,
            default => true,
        };
    }

    /**
     * Whether a failure of this category is worth a same-provider retry.
     *
     * Authentication failures are never retried on the same credential; a
     * validation rejection is the request being wrong, not the provider.
     */
    public function mayRetry(): bool
    {
        return match ($this) {
            self::Authentication,
            self::Validation,
            self::InvalidOutput,
            self::ContentPolicy => false,
            default => true,
        };
    }
}
