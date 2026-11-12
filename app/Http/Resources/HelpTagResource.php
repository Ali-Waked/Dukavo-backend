<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HelpTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Help Center tag for the platform admin, with its usage count.
 *
 * @property-read HelpTag $resource
 */
class HelpTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'slug' => $this->slug,
            'article_count' => $this->whenCounted('articles'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
