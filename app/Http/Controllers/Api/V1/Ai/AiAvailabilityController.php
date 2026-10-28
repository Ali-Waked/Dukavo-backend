<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\Features\FeatureRegistry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiAvailabilityController extends Controller
{
    public function __construct(private readonly FeatureRegistry $registry) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) {
            return ApiResponse::success('AI availability.', []);
        }

        return ApiResponse::success('AI availability.', $this->registry->availableFor($actor));
    }
}
