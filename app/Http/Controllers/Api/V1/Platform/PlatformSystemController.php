<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\Configuration\ConfigurationRegistry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A read-only deployment summary for the Maintenance and Health pages.
 *
 * It reports which infrastructure the running process actually uses so an
 * operator does not assume Redis, a queue worker or a scheduler that is not
 * deployed. Every value is a name or a count; no secret is included.
 */
class PlatformSystemController extends Controller
{
    public function __construct(
        private readonly ConfigurationRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success('', [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'timezone' => (string) config('app.timezone'),
            'locale' => (string) config('app.locale'),
            'database' => (string) config('database.default'),
            'cache' => (string) config('cache.default'),
            'queue' => (string) config('queue.default'),
            'session' => (string) config('session.driver'),
            'mailer' => (string) config('mail.default'),
            'filesystem' => (string) config('filesystems.default'),
            'maintenance' => [
                'enabled' => (bool) config('dukavo.maintenance.enabled', false),
                'message' => (string) config('dukavo.maintenance.message', ''),
                'retry_after_seconds' => (int) config('dukavo.maintenance.retry_after_seconds', 3600),
            ],
            'configuration' => [
                'categories' => count($this->registry->categories()),
                'definitions' => count($this->registry->all()),
                'overrides' => PlatformSetting::query()->count(),
                'secret_overrides' => PlatformSetting::query()->where('is_secret', true)->count(),
            ],
        ]);
    }
}
