<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Customer-safe product payload.
 *
 * Cost, internal ids and stock across other stores are deliberately absent: a
 * shopper only needs what can be bought from this storefront.
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

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'is_taxable' => $this->is_taxable,
            'in_stock' => $available > 0,
            'available' => max(0, $available),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ] : null),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image): array => [
                'url' => Storage::disk($image->disk)->url($image->path),
                'alt_text' => $image->alt_text,
                'is_primary' => $image->is_primary,
            ])->values()),
            'variants' => PublicProductVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
