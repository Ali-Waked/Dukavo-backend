<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HelpArticleFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reader answer on the platform feedback review list.
 *
 * The writer is shown by their account name when signed in and as "anonymous"
 * otherwise; the IP fingerprint is never included — it exists only to stop
 * duplicate votes, not to identify anyone. The comment is plain text.
 *
 * @property-read HelpArticleFeedback $resource
 */
class HelpArticleFeedbackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article' => $this->whenLoaded('article', fn (): array => [
                'id' => $this->article->id,
                'title' => $this->article->title,
                'slug' => $this->article->slug,
            ]),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->getKey(),
                'name' => $this->user->name,
            ]),
            'helpful' => $this->helpful,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
