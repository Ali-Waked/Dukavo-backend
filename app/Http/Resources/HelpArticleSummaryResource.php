<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HelpArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin article table: identity and editorial state, never the body.
 */
class HelpArticleSummaryResource extends JsonResource
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
            'title' => $this->title,
            'title_ar' => $this->title_ar,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'is_featured' => $this->is_featured,
            'sort_order' => $this->sort_order,
            'view_count' => $this->view_count,
            'helpful_count' => $this->helpful_count,
            'unhelpful_count' => $this->unhelpful_count,
            'helpfulness' => $this->resource instanceof HelpArticle ? $this->resource->helpfulnessRatio() : null,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
