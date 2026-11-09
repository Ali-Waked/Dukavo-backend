<?php

declare(strict_types=1);

namespace App\Events\Notifications;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Read, unread, delete or "mark all read" from one of a user's devices.
 *
 * The action itself is already committed; broadcasting it keeps a second tab,
 * phone or browser honest without each device polling. Only ids and counts are
 * sent — never the notification body.
 */
final class NotificationStateChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public readonly User $user,
        public readonly string $action,
        public readonly array $ids = [],
        public readonly int $unreadCount = 0,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->user->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'notification.state';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'ids' => $this->ids,
            'unread_count' => $this->unreadCount,
        ];
    }
}
