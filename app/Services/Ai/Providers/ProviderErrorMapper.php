<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Enums\AiErrorCategory;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\Response;

/**
 * Turns a provider's HTTP response into Dukavo's normalized failure.
 *
 * Every adapter funnels its failures through here so the execution engine
 * only ever reasons about {@see AiErrorCategory}, never about one vendor's
 * error spelling. The provider's message body is never propagated to a
 * client: only the category, the HTTP status and a machine code travel on,
 * and none of those can contain a credential.
 */
final class ProviderErrorMapper
{
    /**
     * Map a failed HTTP response onto the domain exceptions.
     *
     * Content-policy refusals are detected by the marker words providers use;
     * they must fail with `content_policy` so the engine refuses to route
     * the same content to another vendor.
     */
    public static function map(Response $response, string $providerKey): AiUnavailable|AiRequestFailed
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return AiUnavailable::authentication(
                $providerKey,
                $status,
            );
        }

        if ($status === 429) {
            return AiUnavailable::rateLimitedByProvider(
                $providerKey,
                self::retryAfterSeconds($response),
            );
        }

        if ($status === 400 || $status === 404 || $status === 422) {
            if (self::looksLikeContentPolicy($response)) {
                return AiRequestFailed::contentPolicy($status, self::providerCode($response));
            }

            return AiRequestFailed::providerRejected($status, self::providerCode($response));
        }

        if ($status >= 500) {
            return AiUnavailable::providerStatus($providerKey, $status);
        }

        if (self::looksLikeContentPolicy($response)) {
            return AiRequestFailed::contentPolicy($status, self::providerCode($response));
        }

        return AiRequestFailed::providerRejected($status, self::providerCode($response));
    }

    /**
     * The category a connection/timeout failure belongs to.
     */
    public static function categoryForStatus(int $status): AiErrorCategory
    {
        return match (true) {
            $status === 401 || $status === 403 => AiErrorCategory::Authentication,
            $status === 429 => AiErrorCategory::RateLimit,
            $status >= 500 => AiErrorCategory::ServerError,
            $status === 400 || $status === 404 || $status === 422 => AiErrorCategory::Validation,
            default => AiErrorCategory::Unknown,
        };
    }

    private static function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '' || ! is_numeric($header)) {
            return null;
        }

        return max(0, (int) $header);
    }

    /**
     * Whether the provider refused the content rather than the request.
     *
     * A refusal is a decision, not an outage: the engine must not ask a
     * second vendor for the same content. The markers are the words the
     * supported providers use in their refusal bodies.
     */
    private static function looksLikeContentPolicy(Response $response): bool
    {
        $body = strtolower((string) $response->body());

        foreach ([
            'content_policy',
            'content policy',
            'safety system',
            'safety system\u2019s',
            "safety system's",
            'flagged as sensitive',
            'blocked by content filtering',
            'content filter',
            'prompt was rejected',
            'violates our usage policies',
        ] as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        $code = strtolower((string) self::providerCode($response));

        return in_array($code, ['content_policy_violation', 'content_filter', 'moderation_blocked', 'safety_violation'], true);
    }

    private static function providerCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        if (is_string($code) && $code !== '') {
            return $code;
        }

        $code = $response->json('type');

        if (is_string($code) && $code !== '') {
            return $code;
        }

        $status = $response->json('error.status');

        return is_string($status) && $status !== '' ? $status : null;
    }
}
