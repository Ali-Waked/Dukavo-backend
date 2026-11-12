<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Configuration\PlatformConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform configuration change history.
 *
 * The rows come from the shared audit log, narrowed to setting records. Secret
 * values are redacted by the write service before they reach the audit trail,
 * so this endpoint has nothing further to hide.
 */
class PlatformSettingHistoryController extends Controller
{
    public function __construct(private readonly PlatformConfigurationService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $key = $request->filled('key') ? (string) $request->string('key') : null;

        return ApiResponse::success('', [
            'changes' => $this->settings->history(
                $request->integer('limit', 50),
                $key,
            ),
        ]);
    }
}
