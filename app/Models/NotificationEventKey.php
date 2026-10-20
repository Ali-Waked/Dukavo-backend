<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency ledger for a logical notification event.
 *
 * `key` is unique across the table. The dispatcher only proceeds when the key
 * is absent, or — for a recurring event — when the event's cooldown has
 * elapsed since `last_notified_at`. That single check is what makes a retried
 * job, a concurrent request, or a double-fired observer harmless.
 */
#[Fillable(['key', 'scope', 'last_notified_at', 'notifications_count'])]
class NotificationEventKey extends Model
{
    protected function casts(): array
    {
        return [
            'last_notified_at' => 'datetime',
            'notifications_count' => 'integer',
        ];
    }
}
