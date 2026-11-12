<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HelpArticle;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A full Help Center article in the reader's language.
 *
 * `body` is the sanitised HTML stored on write; the approved translation is
 * sanitised by the same service, so both are safe to render. A missing
 * translation falls back to English field by field rather than serving an
 * article whose title and body disagree. SEO copy is resolved here so the
 * public page's metadata matches the language the visitor is reading.
 *
 * @property-read HelpArticle $resource
 */
class PublicHelpArticleResource extends JsonResource
{
    /**
     * @param  array{previous: ?HelpArticle, next: ?HelpArticle}  $neighbours
     */
    public function __construct($resource, private readonly array $neighbours = [])
    {
        parent::__construct($resource);
    }

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
            'body' => $body,
            'cover_image_url' => $this->coverImageUrl(),
            'video_url' => $this->video_url,
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
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->getKey(),
                'name' => $this->author->name,
            ]),
            'reading_minutes' => $this->resource->readingMinutesFor($body),
            'view_count' => $this->view_count,
            'helpful_count' => $this->helpful_count,
            'unhelpful_count' => $this->unhelpful_count,
            'helpfulness' => $this->resource->helpfulnessRatio(),
            'meta_title' => $this->resource->localizedMetaTitle($locale),
            'meta_description' => $this->resource->localizedMetaDescription($locale),
            'meta_keywords' => $this->meta_keywords,
            'related_articles' => $this->relatedSummaries($locale),
            'previous' => $this->neighbourSummary($this->neighbours['previous'] ?? null, $locale),
            'next' => $this->neighbourSummary($this->neighbours['next'] ?? null, $locale),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'locale' => $this->resource->resolvedLocale($locale),
        ];
    }

    /**
     * Related reading, resolved in the reader's language. A related article the
     * viewer may not read never appears here: the relation is only loaded
     * through the reader services, which apply the visibility set first.
     *
     * @return list<array<string, mixed>>
     */
    private function relatedSummaries(string $locale): array
    {
        if (! $this->resource->relationLoaded('relatedArticles')) {
            return [];
        }

        return $this->resource->relatedArticles
            ->map(fn (HelpArticle $article): array => [
                'slug' => $article->localizedSlug($locale),
                'title' => $article->localizedTitle($locale),
                'excerpt' => $article->localizedExcerpt($locale),
            ])
            ->all();
    }

    /**
     * @return ?array{slug: string, title: string}
     */
    private function neighbourSummary(?HelpArticle $article, string $locale): ?array
    {
        if ($article === null) {
            return null;
        }

        return [
            'slug' => $article->localizedSlug($locale),
            'title' => $article->localizedTitle($locale),
        ];
    }
}
