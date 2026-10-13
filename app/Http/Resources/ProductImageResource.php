<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @property-read ProductImage $resource
 */
class ProductImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'alt_text' => $this->alt_text,
            'is_primary' => $this->is_primary,
            'position' => $this->position,
            'url' => Storage::disk($this->disk)->url($this->path),
            'path' => $this->path,
            'disk' => $this->disk,
        ];
    }
}
