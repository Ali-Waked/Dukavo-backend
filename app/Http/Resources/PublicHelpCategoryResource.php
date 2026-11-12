<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HelpCategory;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Help Center category in the reader's language, with the number of
 * articles the viewer may read inside it.
 *
 * @property-read HelpCategory $resource
 * @property-read int $articles_count
 */
class PublicHelpCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->resource->localizedName($locale),
            'description' => $this->resource->localizedDescription($locale),
            'icon' => $this->icon,
            'is_featured' => $this->is_featured,
            'article_count' => (int) ($this->articles_count ?? 0),
        ];
    }
}
