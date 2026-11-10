<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FaqEntry;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public FAQ entry in the visitor's language.
 *
 * @property-read FaqEntry $resource
 */
class PublicFaqEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);

        return [
            'id' => $this->id,
            'question' => $this->resource->localizedQuestion($locale),
            'answer' => $this->resource->localizedAnswer($locale),
            'category' => $this->category,
            'sort_order' => $this->sort_order,
            'locale' => $locale,
        ];
    }
}
