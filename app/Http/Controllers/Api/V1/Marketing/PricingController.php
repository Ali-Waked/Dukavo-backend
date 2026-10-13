<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Resources\PricingPlanResource;
use App\Models\PricingPlan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public pricing.
 *
 * Only active plans are listed, and a plan whose price is null is included:
 * that combination means "announced, not yet priced", and the resource passes
 * the null through so the frontend can render an honest coming-soon state
 * instead of a fabricated number. The list is small and ordered, so it is
 * fetched whole rather than paginated — a pricing page renders in one call.
 *
 * The response carries the standard `success` envelope to match its `features`
 * sibling in the same public group: a visitor's client should not have to
 * special-case which marketing reads acknowledge the envelope and which do not.
 */
class PricingController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(data: PricingPlanResource::collection(
            PricingPlan::query()->active()->ordered()->get(),
        ));
    }
}
