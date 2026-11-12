<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\Product;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Customer-safe product payload.
 *
 * Cost, internal ids and stock across other stores are deliberately absent: a
 * shopper only needs what can be bought from this storefront. Every prose field
 * is resolved through the store's language fallback chain, and only approved
 * copy is ever served.
 *
 * @property-read Product $resource
 */
class PublicProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $available = (int) $this->variants->sum(
            fn ($variant): int => (int) $variant->inventories->sum(fn ($inventory): int => $inventory->available())
        );

        $locale = PreferredLocale::from($request);
        $product = $this->resource;
        $enabled = $this->store?->enabled_locales;
        $availableLocales = is_array($enabled) && $enabled !== []
            ? array_values($enabled)
            : (array) config('dukavo.locales.enabled_default', ['en', 'ar']);

        return [
            'slug' => $product->localizedSlug($locale),
            'canonical_slug' => $this->slug,
            'localized_slugs' => $product->localized_slugs ?? [],
            'name' => $product->localizedName($locale),
            'short_description' => $product->localizedShortDescription($locale),
            'description' => $product->localizedDescription($locale),
            'highlights' => $product->localizedHighlights($locale),
            'features' => $product->localizedFeatures($locale),
            'specifications' => $product->localizedSpecifications($locale),
            'tags' => $product->localizedTags($locale),
            'seo' => [
                'title' => $product->localizedSeoTitle($locale),
                'description' => $product->localizedSeoDescription($locale),
                'keywords' => $product->localizedSeoKeywords($locale),
            ],
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'is_taxable' => $this->is_taxable,
            'in_stock' => $available > 0,
            'available' => max(0, $available),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'slug' => $this->category->slug,
                'name' => $this->category->localizedName($locale),
            ] : null),
            'locale' => $locale,
            'direction' => in_array($locale, ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr',
            'default_locale' => $this->store?->default_locale ?? (string) config('dukavo.locales.default', 'en'),
            'available_locales' => $availableLocales,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image): array => [
                'url' => Storage::disk($image->disk)->url($image->path),
                'alt_text' => $image->localizedAltText($locale),
                'caption' => $image->localizedCaption($locale),
                'is_primary' => $image->is_primary,
            ])->values()),
            'variants' => PublicProductVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
