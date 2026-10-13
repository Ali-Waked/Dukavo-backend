<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePricingPlanRequest;
use App\Http\Requests\Platform\UpdatePricingPlanRequest;
use App\Http\Resources\PricingPlanResource;
use App\Models\PricingPlan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Pricing plan administration.
 *
 * Platform-level configuration, so the routes live behind the `platform`
 * middleware and outside any tenant context: the marketing site's prices are
 * Dukavo's, not any merchant's. Deleting a plan is allowed because it only
 * removes marketing copy — no order, invoice or customer references it.
 */
class PricingPlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $plans = PricingPlan::query()
            ->when($request->filled('search'), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('name', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('slug', 'like', '%'.$request->string('search')->toString().'%')))
            ->when($request->filled('active'), fn ($query) => $query
                ->where('active', $request->boolean('active')))
            ->ordered()
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return PricingPlanResource::collection($plans);
    }

    public function store(StorePricingPlanRequest $request): JsonResponse
    {
        $attributes = $request->safe()->all();
        // The slug is optional in the form; deriving it from the name keeps
        // the public URL stable and human readable. Str::slug also normalises
        // whatever casing the client happened to send.
        $attributes['slug'] = Str::slug((string) ($attributes['slug'] ?? $attributes['name'])) ?: 'plan';

        return ApiResponse::created('Pricing plan created.', new PricingPlanResource(PricingPlan::query()->create($attributes)));
    }

    public function show(PricingPlan $pricingPlan): PricingPlanResource
    {
        return new PricingPlanResource($pricingPlan);
    }

    public function update(UpdatePricingPlanRequest $request, PricingPlan $pricingPlan): PricingPlanResource
    {
        $pricingPlan->fill($request->validated())->save();

        return new PricingPlanResource($pricingPlan);
    }

    public function destroy(PricingPlan $pricingPlan): Response
    {
        $pricingPlan->delete();

        return response()->noContent();
    }
}
