<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HelpArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Help Center article for the platform admin, with the raw columns the edit
 * form needs. The list uses {@see HelpArticleSummaryResource} instead — the
 * table never needs the body.
 *
 * @property-read HelpArticle $resource
 */
class HelpArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn (): array => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->getKey(),
                'name' => $this->author->name,
            ]),
            'title' => $this->title,
            'title_ar' => $this->title_ar,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'excerpt_ar' => $this->excerpt_ar,
            'body' => $this->body,
            'body_ar' => $this->body_ar,
            'cover_image_url' => $this->coverImageUrl(),
            'video_url' => $this->video_url,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'is_featured' => $this->is_featured,
            'sort_order' => $this->sort_order,
            'published_at' => $this->published_at?->toIso8601String(),
            'view_count' => $this->view_count,
            'helpful_count' => $this->helpful_count,
            'unhelpful_count' => $this->unhelpful_count,
            'helpfulness' => $this->resource->helpfulnessRatio(),
            'meta_title' => $this->meta_title,
            'meta_title_ar' => $this->meta_title_ar,
            'meta_description' => $this->meta_description,
            'meta_description_ar' => $this->meta_description_ar,
            'meta_keywords' => $this->meta_keywords,
            'tags' => $this->whenLoaded('tags', fn (): array => $this->resource->tags
                ->map(fn ($tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'name_ar' => $tag->name_ar,
                    'slug' => $tag->slug,
                ])
                ->all()),
            'related_article_ids' => $this->whenLoaded('relatedArticles', fn (): array => $this->resource->relatedArticles
                ->map(fn (HelpArticle $article): int => (int) $article->id)
                ->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
