<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire form of a notification for its owner.
 *
 * Only the fields the bell and the centre render are exposed. The recipient,
 * tenant id and dedupe key stay internal: the caller already knows who they
 * are, and the other two are routing bookkeeping no client should depend on.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'event_key' => $this->event_key,
            'category' => $this->category,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->action_url,
            // Named `params`, not `data`: the API envelope already owns `data`,
            // and a resource field of that name collapses the wrapper.
            'params' => $this->data ?? [],
            'read' => $this->isRead(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
