<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ai\AiProviderResource;
use App\Models\AiProvider;
use App\Models\AiTask;
use App\Services\Ai\Admin\AiUsageAnalytics;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Providers\ProviderCatalog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The AI administration overview.
 *
 * One read that answers the dashboard's opening questions: is the platform
 * serving, which providers exist and are they configured and healthy, how
 * much is being spent, and which catalogue providers are still unregistered.
 */
final class AiAdminOverviewController extends Controller
{
    public function __construct(
        private readonly AiUsageAnalytics $analytics,
        private readonly AiSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $since = Carbon::now()->subDays($this->days($request))->startOfDay();

        $providers = AiProvider::query()
            ->with(['platformCredential', 'health'])
            ->withCount(['models', 'routes', 'credentials'])
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        $registered = $providers
            ->map(fn (AiProvider $provider): string => (string) $provider->key)
            ->all();

        return ApiResponse::success('', [
            'ai_enabled' => (bool) config('ai.enabled'),
            'byok' => [
                'enabled' => $this->settings->byokEnabled(),
            ],
            'counts' => [
                'providers' => $providers->count(),
                'providers_enabled' => $providers->where('enabled', true)->count(),
                'tasks' => AiTask::query()->count(),
                'credentials' => $providers->sum('credentials_count'),
            ],
            'totals' => $this->analytics->totals($since),
            'providers' => AiProviderResource::collection($providers)->resolve(),
            'unregistered_catalog' => array_values(array_filter(
                ProviderCatalog::entries(),
                static fn (array $entry): bool => ! in_array($entry['key'], $registered, true),
            )),
        ], meta: ['days' => $this->days($request)]);
    }

    private function days(Request $request): int
    {
        return (int) min(max($request->integer('days', 30), 1), 365);
    }
}
