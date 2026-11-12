<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreHelpTagRequest;
use App\Http\Requests\Platform\UpdateHelpTagRequest;
use App\Http\Resources\HelpTagResource;
use App\Models\HelpTag;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Help Center tag administration.
 *
 * Tags are cross-links between articles ("whatsapp", "getting-started") owned
 * by the platform, so their catalogue is managed here next to the articles
 * that use them. Deleting a tag only removes the link, never the articles.
 */
class HelpTagController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tags = HelpTag::query()
            ->withCount('articles')
            ->when($request->filled('search'), fn ($query) => $query
                ->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 50), 200))
            ->withQueryString();

        return HelpTagResource::collection($tags);
    }

    public function store(StoreHelpTagRequest $request): JsonResponse
    {
        return ApiResponse::created('Tag created.', new HelpTagResource(
            HelpTag::query()->create($request->safe()->all())->loadCount('articles'),
        ));
    }

    public function show(HelpTag $helpTag): HelpTagResource
    {
        return new HelpTagResource($helpTag->loadCount('articles'));
    }

    public function update(UpdateHelpTagRequest $request, HelpTag $helpTag): JsonResponse
    {
        $helpTag->fill($request->safe()->all())->save();

        return ApiResponse::success('Tag updated.', new HelpTagResource($helpTag->loadCount('articles')));
    }

    public function destroy(HelpTag $helpTag): Response
    {
        $helpTag->delete();

        return response()->noContent();
    }
}
