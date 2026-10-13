<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Newsletter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A full newsletter edition, including its sanitised HTML body.
 *
 * @property-read Newsletter $resource
 */
class NewsletterResource extends JsonResource
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
            // Already sanitised on write; safe to render as markup on the client.
            'body' => $this->body,
            'cover_image_url' => $this->coverImageUrl(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
