<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Newsletter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A newsletter edition without its body, for list surfaces.
 *
 * The archive and the admin table never render the article copy, so shipping it
 * on every row would be wasted payload; the detail read returns the full
 * {@see NewsletterResource} instead.
 *
 * @property-read Newsletter $resource
 */
class NewsletterSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'cover_image_url' => $this->coverImageUrl(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
