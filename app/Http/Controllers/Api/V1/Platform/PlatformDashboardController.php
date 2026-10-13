<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Reporting\PlatformDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The platform operator's overview.
 *
 * Every figure is a real COUNT or GROUP BY against Dukavo's own tables, computed
 * in SQL by {@see PlatformDashboardService}. These tables carry no `tenant_id`,
 * so the endpoint runs outside any tenant context on purpose — it describes the
 * platform, not a merchant. No subscription or revenue metric appears here
 * because Dukavo has no billing system yet; inventing one would misrepresent the
 * product.
 */
class PlatformDashboardController extends Controller
{
    public function __construct(private readonly PlatformDashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'string', Rule::in(PlatformDashboardService::ranges())],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->dashboard->overview(
                $validated['range'] ?? PlatformDashboardService::DEFAULT_RANGE,
            ),
        ]);
    }
}
