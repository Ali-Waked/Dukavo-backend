<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Configuration\IntegrationHealthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Integration health.
 *
 * Reading returns the last recorded probe; only an explicit test performs
 * network or storage work. That keeps the page free to open and makes the
 * difference between "nobody tested this" and "the last test failed" visible.
 */
class PlatformIntegrationHealthController extends Controller
{
    public function __construct(private readonly IntegrationHealthService $health) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success('', [
            'integrations' => $this->health->all(),
        ]);
    }

    public function check(Request $request, string $integration): JsonResponse
    {
        try {
            $result = $this->health->check($integration, $request->user());
        } catch (InvalidArgumentException) {
            abort(404, 'Unknown integration.');
        }

        return ApiResponse::success('Integration probe completed.', [
            'integration' => $result,
            'integrations' => $this->health->all(),
        ]);
    }
}
