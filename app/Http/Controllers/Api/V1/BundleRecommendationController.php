<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bundle\BundleRecommendationRequest;
use App\Http\Requests\Bundle\ExplainBundleRecommendationRequest;
use App\Models\Product;
use App\Services\Ai\Features\BundleExplanationAssistant;
use App\Services\Bundles\BundleRecommendationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Recommendations and their optional grounded explanation.
 *
 * Both endpoints recompute the evidence from the database. A client cannot
 * submit an order count or a claimed lift and have it repeated back by the
 * model.
 */
class BundleRecommendationController extends Controller
{
    public function __construct(
        private readonly BundleRecommendationService $recommendations,
        private readonly BundleExplanationAssistant $explanations,
    ) {}

    public function index(BundleRecommendationRequest $request): JsonResponse
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));

        $payload = $this->recommendations->recommend(
            $product,
            $request->filled('store_id') ? $request->integer('store_id') : null,
            $request->integer('limit', 5),
            $request->filled('expected_catalog_fingerprint') ? $request->string('expected_catalog_fingerprint')->toString() : null,
        );

        return ApiResponse::success('', $payload);
    }

    public function explain(ExplainBundleRecommendationRequest $request): JsonResponse
    {
        $anchor = Product::query()->findOrFail($request->integer('product_id'));
        $partner = Product::query()->findOrFail($request->integer('partner_product_id'));
        $storeId = $request->filled('store_id') ? $request->integer('store_id') : null;

        $evidence = $this->recommendations->evidenceFor($anchor, $partner, $storeId);
        $explanation = $this->explanations->explain($anchor, $partner, $evidence, $request->user());

        return ApiResponse::success('', [
            'evidence' => $evidence,
            'explanation' => $explanation['explanation'],
            'usage' => $explanation['usage'],
        ]);
    }
}
