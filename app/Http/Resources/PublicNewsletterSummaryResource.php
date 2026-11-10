<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Newsletter;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public article summary in the visitor's language.
 *
 * The canonical `title`/`excerpt` fields are resolved here rather than on the
 * client: every public surface (archive, home strip, related articles) then
 * renders the same language without repeating the fallback rule. The admin
 * resource keeps the raw English and Arabic columns for editing.
 *
 * @property-read Newsletter $resource
 */
class PublicNewsletterSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = PreferredLocale::from($request);
        $body = $this->resource->localizedBody($locale);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->resource->localizedTitle($locale),
            'excerpt' => $this->resource->localizedExcerpt($locale),
            'cover_image_url' => $this->coverImageUrl(),
            'reading_minutes' => $this->readingMinutesFor($body),
            'published_at' => $this->published_at?->toIso8601String(),
            'locale' => $this->resource->resolvedLocale($locale),
        ];
    }
}
