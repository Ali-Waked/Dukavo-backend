<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The AI serving posture, as read-only configuration.
 *
 * The master switch, BYOK, transport bounds and feature caps are
 * environment decisions, not database rows: changing them is a deployment
 * change, so this surface only explains them. Everything an operator can
 * change at runtime lives in the provider, routing and prompt endpoints.
 */
final class AiAdminSettingsController extends Controller
{
    public function __construct(private readonly AiSettings $settings) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success('', [
            'enabled' => (bool) config('ai.enabled'),
            'byok' => [
                'enabled' => $this->settings->byokEnabled(),
                'managed_by' => 'environment',
            ],
            'transport' => (array) config('ai.transport'),
            'context' => (array) config('ai.context'),
            'usage' => [
                'retention_days' => (int) config('ai.usage.retention_days'),
            ],
            'pricing' => (array) config('ai.pricing'),
            'features' => array_map(
                static fn (array $feature): array => [
                    'enabled' => (bool) ($feature['enabled'] ?? false),
                    'daily_per_user' => (int) ($feature['daily_per_user'] ?? 0),
                    'daily_per_tenant' => (int) ($feature['daily_per_tenant'] ?? 0),
                    'daily_tokens_per_user' => (int) ($feature['daily_tokens_per_user'] ?? 0),
                    'daily_tokens_per_tenant' => (int) ($feature['daily_tokens_per_tenant'] ?? 0),
                    'max_output_tokens' => (int) ($feature['max_output_tokens'] ?? 0),
                ],
                (array) config('ai.features'),
            ),
            'legacy_chain' => [
                'provider' => (string) config('ai.provider'),
                'fallback_providers' => (array) config('ai.fallback_providers'),
            ],
        ], meta: [
            'read_only' => true,
            'note' => 'These values are set through the environment and configuration; runtime administration lives in providers, routing, prompts and usage.',
        ]);
    }
}
