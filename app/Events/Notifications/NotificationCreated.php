<?php

declare(strict_types=1);

namespace App\Events\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new in-app notification, delivered to its owner's private channel.
 *
 * The event carries only the notification the recipient is already allowed to
 * read. It is broadcast after the database transaction commits, so a browser
 * can never be told about a row that was rolled back. Listening clients still
 * fetch the authoritative list from Laravel; this event is an accelerator, not
 * a second source of truth.
 */
final class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->notification->notifiable_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $notification = $this->notification;
        $user = $notification->notifiable;

        $unread = 0;

        if ($user instanceof User) {
            $unread = $user->notifications()->whereNull('read_at')->whereNull('archived_at')->count();
        }

        return [
            'notification' => [
                'id' => $notification->getKey(),
                'event_key' => $notification->event_key,
                'category' => $notification->category,
                'severity' => $notification->severity?->value,
                'priority' => $notification->priority?->value,
                'title' => $notification->title,
                'body' => $notification->body,
                'action_url' => $notification->action_url,
                'params' => $notification->data ?? [],
                'tenant_id' => $notification->tenant_id,
                'store_id' => $notification->store_id,
                'read' => false,
                'read_at' => null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ],
            'unread_count' => $unread,
        ];
    }
}
