<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Newsletter;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A full public article in the visitor's language.
 *
 * `body` is the sanitised HTML that was stored on write; the translation is
 * sanitised by the same service, so both are safe to render. A missing
 * translation falls back to English field by field rather than serving an
 * article whose title and body disagree.
 *
 * @property-read Newsletter $resource
 */
class PublicNewsletterResource extends JsonResource
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
            'body' => $body,
            'cover_image_url' => $this->coverImageUrl(),
            'meta_title' => $this->resource->localizedMetaTitle($locale),
            'meta_description' => $this->resource->localizedMetaDescription($locale),
            'reading_minutes' => $this->readingMinutesFor($body),
            'published_at' => $this->published_at?->toIso8601String(),
            'locale' => $this->resource->resolvedLocale($locale),
        ];
    }
}
