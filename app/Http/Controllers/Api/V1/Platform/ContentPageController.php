<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateContentPageRequest;
use App\Http\Resources\ContentPageResource;
use App\Models\ContentPage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Administration of the public content pages.
 *
 * Deliberately no create and no delete: the three pages (terms, privacy, about)
 * are part of the product and are seeded, so the only meaningful operations are
 * reading them and replacing their content. Publishing stamps `published_at`
 * the first time a page goes live — that first stamp is what the public "last
 * updated" date reads, so it does not move every time a draft is saved.
 */
class ContentPageController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(data: ContentPageResource::collection(
            ContentPage::query()->orderBy('key')->orderBy('locale')->get(),
        ));
    }

    public function show(ContentPage $contentPage): ContentPageResource
    {
        return new ContentPageResource($contentPage);
    }

    public function update(UpdateContentPageRequest $request, ContentPage $contentPage): ContentPageResource
    {
        $attributes = $request->validated();

        if (array_key_exists('sections', $attributes)) {
            $attributes['sections'] = $this->normaliseSections($attributes['sections']);
        }

        if (array_key_exists('active', $attributes)) {
            // Stamp the first publication only; re-saving a live page must not
            // silently make it look freshly updated.
            $attributes['published_at'] = $attributes['active']
                ? ($contentPage->published_at ?? now())
                : null;
        }

        $contentPage->fill($attributes)->save();

        return new ContentPageResource($contentPage);
    }

    /**
     * Normalise each incoming section to a stable shape.
     *
     * `layout` defaults to `prose` so a client that only cares about long-form
     * copy does not have to send it, and every key the renderer reads is always
     * present in stored data.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return list<array{id: string, title: string, layout: string, body: string}>
     */
    private function normaliseSections(array $sections): array
    {
        return array_map(static fn (array $section): array => [
            'id' => (string) $section['id'],
            'title' => (string) $section['title'],
            'layout' => (string) ($section['layout'] ?? 'prose'),
            'body' => (string) $section['body'],
        ], array_values($sections));
    }
}
