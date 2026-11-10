<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContentPage;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public content page in the visitor's language.
 *
 * A page that already exists as a row in the requested language is served as
 * is. A page that only exists in the default language is served through its
 * approved translation overlay, so translating a policy never requires
 * duplicating the whole document.
 *
 * @property-read ContentPage $resource
 */
class PublicContentPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);

        return [
            'id' => $this->id,
            'key' => $this->key,
            'locale' => $this->resource->resolvedLocale($locale),
            'title' => $this->resource->localizedTitle($locale),
            'intro' => $this->resource->localizedIntro($locale),
            // Always an array, never null: the client maps over it directly.
            'sections' => $this->sections ?? [],
            'active' => $this->active,
            'meta_title' => $this->resource->localizedMetaTitle($locale),
            'meta_description' => $this->resource->localizedMetaDescription($locale),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
