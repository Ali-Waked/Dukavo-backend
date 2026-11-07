<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\Category;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category as a shopper sees it.
 *
 * `name` and `description` are resolved to the requested language, with the
 * canonical English value as the per-field fallback. `locale` tells the client
 * which direction to render the copy in; the slug never changes with language.
 *
 * @property-read Category $resource
 */
class PublicCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);

        return [
            'slug' => $this->slug,
            'name' => $this->resource->localizedName($locale),
            'description' => $this->resource->localizedDescription($locale),
            'products_count' => (int) ($this->products_count ?? 0),
            'locale' => $locale,
        ];
    }
}
