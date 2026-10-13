<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentPageResource;
use App\Models\ContentPage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A single public content page (terms, privacy, about), addressed by its stable
 * key rather than its id so a URL never changes when rows are re-created.
 *
 * The page is served in the visitor's language: the same key exists once per
 * locale, and the `Accept-Language` header the SPA already sends decides which
 * row is returned. A language without a published row falls back to the default
 * locale, so a missing translation degrades to English rather than to a 404.
 *
 * Only published pages are served: an unpublished page is a 404, not an empty
 * document, so a half-written policy can never be read by a visitor. The whole
 * page — sections included — is returned in one payload because it is always
 * rendered as one document.
 */
class ContentPageController extends Controller
{
    public function show(Request $request, string $key): JsonResponse
    {
        $page = $this->published($key, $this->preferredLocale($request));

        if ($page === null) {
            $page = $this->published($key, (string) config('dukavo.locales.default', 'en'));
        }

        abort_if($page === null, 404);

        return ApiResponse::success(data: new ContentPageResource($page));
    }

    private function published(string $key, string $locale): ?ContentPage
    {
        return ContentPage::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->active()
            ->first();
    }

    /**
     * The best supported language for this request, from `Accept-Language`.
     *
     * Reads the first tag only (`ar-EG,ar;q=0.9` → `ar`): the platform stores a
     * language, not a region, and the header is a hint — an unsupported or
     * absent value yields the default.
     */
    private function preferredLocale(Request $request): string
    {
        $supported = (array) config('dukavo.locales.supported', ['en']);
        $default = (string) config('dukavo.locales.default', 'en');
        $header = (string) $request->header('Accept-Language', '');

        $first = trim(explode(',', $header)[0] ?? '');
        $tag = strtolower(trim(explode(';', $first)[0] ?? ''));
        $language = explode('-', $tag)[0];

        return in_array($language, $supported, true) ? $language : $default;
    }
}
