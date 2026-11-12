<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HelpArticle;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Help Center article summary in the reader's language.
 *
 * The canonical `title`/`excerpt` are resolved here rather than on the client,
 * matching the newsletter archive: every public surface renders the same
 * language without repeating the fallback rule. `localized_slug` is what the
 * reader's language should link to.
 *
 * @property-read HelpArticle $resource
 */
class PublicHelpArticleSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);
        $body = $this->resource->localizedBody($locale);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'localized_slug' => $this->resource->localizedSlug($locale),
            'title' => $this->resource->localizedTitle($locale),
            'excerpt' => $this->resource->localizedExcerpt($locale),
            'cover_image_url' => $this->coverImageUrl(),
            'category' => $this->whenLoaded('category', fn (): array => [
                'slug' => $this->category->slug,
                'name' => $this->category->localizedName($locale),
                'icon' => $this->category->icon,
            ]),
            'tags' => $this->whenLoaded('tags', fn (): array => $this->resource->tags
                ->map(fn ($tag): array => [
                    'slug' => $tag->slug,
                    'name' => $tag->localizedName($locale),
                ])
                ->all()),
            'is_featured' => $this->is_featured,
            'reading_minutes' => $this->resource->readingMinutesFor($body),
            'view_count' => $this->view_count,
            'helpfulness' => $this->resource->helpfulnessRatio(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'locale' => $this->resource->resolvedLocale($locale),
        ];
    }
}
