<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreFaqEntryRequest;
use App\Http\Requests\Platform\UpdateFaqEntryRequest;
use App\Http\Resources\FaqEntryResource;
use App\Models\FaqEntry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * FAQ administration.
 *
 * Platform-level marketing content: the questions answer what Dukavo does,
 * so they belong to no tenant. `sort_order` decides the public order and is
 * editable here; the public endpoint reads it verbatim.
 */
class FaqEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $entries = FaqEntry::query()
            ->when($request->filled('search'), fn ($query) => $query
                ->where('question', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('category'), fn ($query) => $query
                ->where('category', $request->string('category')->toString()))
            ->when($request->filled('active'), fn ($query) => $query
                ->where('active', $request->boolean('active')))
            ->ordered()
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return FaqEntryResource::collection($entries);
    }

    public function store(StoreFaqEntryRequest $request): JsonResponse
    {
        return ApiResponse::created('FAQ entry created.', new FaqEntryResource(FaqEntry::query()->create($request->safe()->all())));
    }

    public function show(FaqEntry $faqEntry): FaqEntryResource
    {
        return new FaqEntryResource($faqEntry);
    }

    public function update(UpdateFaqEntryRequest $request, FaqEntry $faqEntry): FaqEntryResource
    {
        $faqEntry->fill($request->validated())->save();

        return new FaqEntryResource($faqEntry);
    }

    public function destroy(FaqEntry $faqEntry): Response
    {
        $faqEntry->delete();

        return response()->noContent();
    }
}
